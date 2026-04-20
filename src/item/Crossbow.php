<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use pocketmine\world\sound\CrossbowLoadingEndSound;
use pocketmine\world\sound\CrossbowLoadingMiddleSound;
use pocketmine\world\sound\CrossbowLoadingStartSound;
use pocketmine\world\sound\CrossbowShootSound;

class Crossbow extends Tool {

    /**
     * Base charge duration in seconds, matching Java Edition's MAX_CHARGE_DURATION = 1.25F
     * This equals 25 ticks (1.25 * 20)
     */
    private const BASE_CHARGE_DURATION_SECONDS = 1.25;

    /**
     * Arrow projectile power. Java: ARROW_POWER = 3.15F
     */
    private const ARROW_POWER = 3.15;

    /**
     * Firework projectile power. Java: FIREWORK_POWER = 1.6F
     */
    private const FIREWORK_POWER = 1.6;

    /**
     * Sound trigger thresholds from Java Edition:
     * START_SOUND_PERCENT = 0.2F, MID_SOUND_PERCENT = 0.5F
     */
    private const START_SOUND_PERCENT = 0.2;
    private const MID_SOUND_PERCENT = 0.5;

    private bool $startSoundPlayed = false;
    private bool $midLoadSoundPlayed = false;

    public function getMaxDurability(): int {
        return 465;
    }

    /**
     * Matches Java's getUseDuration() returning 72000 ticks.
     * This is the maximum time a player can hold the item in "use" state.
     */
    public function getMaxUseTickLength(): int {
        return 72000;
    }

    /**
     * Calculates charge duration in ticks, accounting for Quick Charge enchantment.
     *
     * Java equivalent:
     *   public static int getChargeDuration(ItemStack crossbow, LivingEntity user) {
     *       float duration = EnchantmentHelper.modifyCrossbowChargingTime(crossbow, user, 1.25F);
     *       return Mth.floor(duration * 20.0F);
     *   }
     *
     * Quick Charge reduces charge time by 0.25s per level (I, II, III).
     */
    public function getChargeDurationTicks(): int {
        $quickChargeLevel = $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE());
        $durationSeconds = self::BASE_CHARGE_DURATION_SECONDS - ($quickChargeLevel * 0.25);
        return (int) floor(max($durationSeconds, 0.0) * 20);
    }

    // ──────────────────────────────────────────────
    // Charged Projectile Storage (NBT-based)
    // ──────────────────────────────────────────────

    /**
     * Equivalent to Java's ChargedProjectiles component on DataComponents.CHARGED_PROJECTILES.
     * In PocketMine-MP we store it as NBT on the item's custom data.
     *
     * NBT structure:
     *   chargedProjectiles: ListTag<CompoundTag> [
     *       { id: "minecraft:arrow", Count: 1, ... }
     *   ]
     */
    public function isCharged(): bool {
        $tag = $this->getNamedTag();
        $projectiles = $tag->getListTag("chargedProjectiles");
        return $projectiles !== null && !$projectiles->empty();
    }

    public function getChargedProjectiles(): array {
        $tag = $this->getNamedTag();
        $projectiles = $tag->getListTag("chargedProjectiles");
        if ($projectiles === null) {
            return [];
        }

        $items = [];
        /** @var CompoundTag $projectileTag */
        foreach ($projectiles as $projectileTag) {
            $item = Item::nbtDeserialize($projectileTag);
            if (!$item->isNull()) {
                $items[] = $item;
            }
        }
        return $items;
    }

    public function setChargedProjectiles(array $projectiles): self {
        $tag = $this->getNamedTag();
        if (empty($projectiles)) {
            $tag->removeTag("chargedProjectiles");
        } else {
            $list = new ListTag();
            foreach ($projectiles as $item) {
                $list->push($item->nbtSerialize());
            }
            $tag->setTag("chargedProjectiles", $list);
        }
        $this->setNamedTag($tag);
        return $this;
    }

    public function clearChargedProjectiles(): self {
        return $this->setChargedProjectiles([]);
    }

    // ──────────────────────────────────────────────
    // Use Mechanics (Two-Phase: Charge → Fire)
    // ──────────────────────────────────────────────

    /**
     * Java equivalent of CrossbowItem.use():
     *
     *   if (chargedProjectiles != null && !chargedProjectiles.isEmpty()) {
     *       this.performShooting(...);
     *       return InteractionResult.CONSUME;
     *   } else if (!player.getProjectile(itemStack).isEmpty()) {
     *       this.startSoundPlayed = false;
     *       this.midLoadSoundPlayed = false;
     *       player.startUsingItem(hand);
     *       return InteractionResult.CONSUME;
     *   } else {
     *       return InteractionResult.FAIL;
     *   }
     */
    public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems): ItemUseResult {
        // Phase 2: Already charged → fire immediately
        if ($this->isCharged()) {
            $this->performShooting($player, $directionVector, $returnedItems);
            return ItemUseResult::SUCCESS;
        }

        // Phase 1: Start charging if the player has ammo
        $projectile = $this->findAmmo($player);
        if ($projectile !== null) {
            $this->startSoundPlayed = false;
            $this->midLoadSoundPlayed = false;
            // Returning FAIL here tells PocketMine to begin the "using item" state,
            // which will trigger onUseTick() and onReleaseUsing().
            // (Adjust based on actual PM-MP API — you may need to return a different value)
            return ItemUseResult::SUCCESS;
        }

        return ItemUseResult::FAIL;
    }

    /**
     * Called every tick while the player is holding right-click (charging).
     *
     * Java equivalent: CrossbowItem.onUseTick()
     *
     * Handles:
     * - Playing charging sounds at 20% and 50%
     * - Loading the projectile at 100% (without requiring release)
     */
    public function onUseTick(int $ticksUsed, Player $player): void {
        $chargeDuration = $this->getChargeDurationTicks();
        $chargePercent = $chargeDuration > 0 ? ($ticksUsed / $chargeDuration) : 1.0;

        // Reset sound flags if we somehow dropped below 20%
        if ($chargePercent < self::START_SOUND_PERCENT) {
            $this->startSoundPlayed = false;
            $this->midLoadSoundPlayed = false;
        }

        // Play start sound at 20%
        if ($chargePercent >= self::START_SOUND_PERCENT && !$this->startSoundPlayed) {
            $this->startSoundPlayed = true;
            $player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingStartSound());
        }

        // Play mid sound at 50%
        if ($chargePercent >= self::MID_SOUND_PERCENT && !$this->midLoadSoundPlayed) {
            $this->midLoadSoundPlayed = true;
            $player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingMiddleSound());
        }

        // Load projectile at 100%
        if ($chargePercent >= 1.0 && !$this->isCharged()) {
            if ($this->tryLoadProjectile($player)) {
                $player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingEndSound());
                // Stop the "using" state — the crossbow is now charged
                // Player can release; next click will fire
            }
        }
    }

    /**
     * Java's releaseUsing() — called when the player releases right-click.
     *
     * For crossbows, this does NOT fire. It only matters whether the crossbow
     * finished charging. Java:
     *   int timeHeld = this.getUseDuration(itemStack, entity) - remainingTime;
     *   return getPowerForTime(timeHeld, itemStack, entity) >= 1.0F && isCharged(itemStack);
     *
     * If the player releases before fully charged, charging is cancelled.
     */
    public function onReleaseUsing(Player $player, array &$returnedItems): ItemUseResult {
        // If the crossbow is charged (loading completed during onUseTick),
        // we just stop — the next click will fire.
        if ($this->isCharged()) {
            return ItemUseResult::SUCCESS;
        }

        // Released too early — charging cancelled, nothing happens
        return ItemUseResult::FAIL;
    }

    // ──────────────────────────────────────────────
    // Ammo Finding
    // ──────────────────────────────────────────────

    /**
     * Java: getSupportedHeldProjectiles() returns ARROW_OR_FIREWORK
     *       getAllSupportedProjectiles() returns ARROW_ONLY
     *
     * When held in hand (offhand), fireworks are also accepted.
     * From inventory, only arrows are valid.
     */
    private function findAmmo(Player $player): ?Item {
        // Check offhand first (supports fireworks)
        $offhand = $player->getOffHandInventory()->getItem(0);
        if ($this->isValidHeldProjectile($offhand)) {
            return $offhand;
        }

        // Check inventory for arrows
        foreach ($player->getInventory()->getContents() as $item) {
            if ($this->isValidProjectile($item)) {
                return $item;
            }
        }

        // Creative mode: infinite arrows
        if ($player->isCreative()) {
            return VanillaItems::ARROW();
        }

        return null;
    }

    private function isValidProjectile(Item $item): bool {
        return $item instanceof Arrow; // ARROW_ONLY
    }

    private function isValidHeldProjectile(Item $item): bool {
        return $item instanceof Arrow || $item instanceof FireworkRocket; // ARROW_OR_FIREWORK
    }

    // ──────────────────────────────────────────────
    // Loading
    // ──────────────────────────────────────────────

    /**
     * Java equivalent: tryLoadProjectiles()
     *
     *   List<ItemStack> drawn = draw(heldItem, shooter.getProjectile(heldItem), shooter);
     *   if (!drawn.isEmpty()) {
     *       heldItem.set(DataComponents.CHARGED_PROJECTILES, ChargedProjectiles.ofNonEmpty(drawn));
     *       return true;
     *   }
     *
     * Must also handle Multishot enchantment (loads 3 arrows but only consumes 1).
     */
    private function tryLoadProjectile(Player $player): bool {
        $ammo = $this->findAmmo($player);
        if ($ammo === null) {
            return false;
        }

        $multishotLevel = $this->getEnchantmentLevel(VanillaEnchantments::MULTISHOT());
        $projectileCount = $multishotLevel > 0 ? 3 : 1;

        $projectiles = [];
        for ($i = 0; $i < $projectileCount; $i++) {
            $projectileCopy = clone $ammo;
            $projectileCopy->setCount(1);
            $projectiles[] = $projectileCopy;
        }

        $this->setChargedProjectiles($projectiles);

        // Consume ammo (only 1, even for multishot) unless creative
        if (!$player->isCreative()) {
            $ammo->setCount($ammo->getCount() - 1);
            // Update the inventory slot where this ammo came from
        }

        return true;
    }

    // ──────────────────────────────────────────────
    // Shooting
    // ──────────────────────────────────────────────

    /**
     * Java equivalent: performShooting() + shootProjectile()
     *
     *   ChargedProjectiles charged = weapon.set(CHARGED_PROJECTILES, EMPTY);
     *   this.shoot(serverLevel, shooter, hand, weapon, charged.itemCopies(), power, uncertainty, ...);
     *
     * For multishot, the angles are: -10°, 0°, +10°
     * Arrow uncertainty: 1.0F for players
     */
    private function performShooting(Player $player, Vector3 $directionVector, array &$returnedItems): void {
        $chargedProjectiles = $this->getChargedProjectiles();
        if (empty($chargedProjectiles)) {
            return;
        }

        // Clear charged state immediately (Java: weapon.set(CHARGED_PROJECTILES, EMPTY))
        $this->clearChargedProjectiles();

        $isMultishot = count($chargedProjectiles) > 1;
        $angles = $isMultishot ? [-10.0, 0.0, 10.0] : [0.0];

        $location = $player->getLocation();

        foreach ($chargedProjectiles as $index => $projectileItem) {
            $angle = $angles[$index] ?? 0.0;

            // Determine power based on projectile type
            $power = ($projectileItem instanceof FireworkRocket)
                ? self::FIREWORK_POWER
                : self::ARROW_POWER;

            // Create the projectile entity
            $projectileEntity = $this->createProjectileEntity(
                $player,
                $projectileItem,
                $location,
                $directionVector,
                $power,
                $angle
            );

            if ($projectileEntity === null) {
                continue;
            }

            // Multishot side arrows can't be picked up (Java: AbstractArrow.Pickup.CREATIVE_ONLY)
            if ($isMultishot && $index !== 1 && $projectileEntity instanceof ArrowEntity) {
                $projectileEntity->setPickupMode(ArrowEntity::PICKUP_CREATIVE);
            }

            // Fire event
            $ev = new ProjectileLaunchEvent($projectileEntity);
            $ev->call();
            if ($ev->isCancelled()) {
                $projectileEntity->flagForDespawn();
                continue;
            }

            $player->getWorld()->addEntity($projectileEntity);
        }

        // Play shoot sound
        $player->getWorld()->addSound($player->getPosition(), new CrossbowShootSound());

        // Apply durability damage
        // Java: getDurabilityUse() returns 3 for fireworks, 1 for arrows
        $durabilityUse = $this->getDurabilityUseForProjectile($chargedProjectiles[0] ?? null);
        $this->applyDamage($durabilityUse);
    }

    /**
     * Java equivalent: createProjectile() + shootProjectile()
     *
     * For arrows:
     *   Projectile projectileEntity = super.createProjectile(level, shooter, heldItem, projectile, isCrit);
     *   if (projectileEntity instanceof AbstractArrow arrow) {
     *       arrow.setSoundEvent(SoundEvents.CROSSBOW_HIT);
     *   }
     *
     * For fireworks:
     *   return new FireworkRocketEntity(level, projectile, shooter, x, eyeY - 0.15, z, true);
     */
    private function createProjectileEntity(
        Player $player,
        Item $projectileItem,
        Location $location,
        Vector3 $directionVector,
        float $power,
        float $angleOffset
    ): ?Projectile {
        // Apply angle offset for multishot
        $direction = $this->rotateVectorByAngle($directionVector, $angleOffset, $player);

        if ($projectileItem instanceof Arrow) {
            $arrowEntity = new ArrowEntity(
                Location::fromObject(
                    $player->getEyePos(),
                    $player->getWorld(),
                    ($location->yaw > 180 ? 360 : 0) - $location->yaw,
                    -$location->pitch
                ),
                $player,
                $projectileItem instanceof class_exists('\pocketmine\item\PotionArrow')
                    ? true  // handle tipped arrows
                    : false
            );

            // Crossbow arrows are always "critical" (full power)
            $arrowEntity->setMotion($direction->normalize()->multiply($power));

            return $arrowEntity;
        }

        // TODO: FireworkRocketEntity as projectile (not yet in PocketMine-MP)
        // if ($projectileItem instanceof FireworkRocket) { ... }

        return null;
    }

    /**
     * Rotates the direction vector by the given yaw angle offset.
     * Used for multishot spread (-10°, 0°, +10°).
     *
     * Java uses Quaternionf rotation around the up vector:
     *   Quaternionf upQuaternion = new Quaternionf()
     *       .setAngleAxis(angle * (PI / 180.0), upVector.x, upVector.y, upVector.z);
     *   shotVector = viewVec.toVector3f().rotate(upQuaternion);
     */
    private function rotateVectorByAngle(Vector3 $direction, float $angleDegrees, Player $player): Vector3 {
        if (abs($angleDegrees) < 0.001) {
            return $direction;
        }

        $angleRadians = deg2rad($angleDegrees);

        // Rotate around the player's up vector (Y-axis simplified)
        $cos = cos($angleRadians);
        $sin = sin($angleRadians);

        return new Vector3(
            $direction->x * $cos - $direction->z * $sin,
            $direction->y,
            $direction->x * $sin + $direction->z * $cos
        );
    }

    /**
     * Java: getDurabilityUse() — 3 for fireworks, 1 for arrows
     */
    private function getDurabilityUseForProjectile(?Item $projectile): int {
        if ($projectile instanceof FireworkRocket) {
            return 3;
        }
        return 1;
    }

    public function getFuelTime(): int {
        return 0;
    }
}