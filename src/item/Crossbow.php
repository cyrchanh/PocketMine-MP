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

class Crossbow extends Tool implements ItemUseTickable {

    private const BASE_CHARGE_DURATION_SECONDS = 1.25;

    private const ARROW_POWER = 3.15;

    private const FIREWORK_POWER = 1.6;

    private const START_SOUND_PERCENT = 0.2;
    private const MID_SOUND_PERCENT = 0.5;

    private bool $startSoundPlayed = false;
    private bool $midLoadSoundPlayed = false;

    public function getMaxDurability(): int {
        return 465;
    }

    public function getMaxUseTickLength(): int {
        return 72000;
    }

    public function getChargeDurationTicks(): int {
        $quickChargeLevel = $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE());
        $durationSeconds = self::BASE_CHARGE_DURATION_SECONDS - ($quickChargeLevel * 0.25);
        return (int) floor(max($durationSeconds, 0.0) * 20);
    }

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

    public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems): ItemUseResult {
        if ($this->isCharged()) {
            $this->performShooting($player, $directionVector, $returnedItems);
            return ItemUseResult::SUCCESS;
        }

        $projectile = $this->findAmmo($player);
        if ($projectile !== null) {
            $this->startSoundPlayed = false;
            $this->midLoadSoundPlayed = false;
            return ItemUseResult::SUCCESS;
        }

        return ItemUseResult::FAIL;
    }

    public function onUsingTick(Player $player, int $ticksUsed, array &$returnedItems): ?ItemUseResult {
        $chargeDuration = $this->getChargeDurationTicks();
        $chargePercent = $chargeDuration > 0 ? ($ticksUsed / $chargeDuration) : 1.0;

        if ($chargePercent < self::START_SOUND_PERCENT) {
            $this->startSoundPlayed = false;
            $this->midLoadSoundPlayed = false;
        }

        if ($chargePercent >= self::START_SOUND_PERCENT && !$this->startSoundPlayed) {
            $this->startSoundPlayed = true;
            $player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingStartSound());
        }

        if ($chargePercent >= self::MID_SOUND_PERCENT && !$this->midLoadSoundPlayed) {
            $this->midLoadSoundPlayed = true;
            $player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingMiddleSound());
        }

        if ($chargePercent >= 1.0 && !$this->isCharged()) {
            if ($this->tryLoadProjectile($player)) {
                $player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingEndSound());
                return ItemUseResult::SUCCESS;
            }
            return ItemUseResult::FAIL;
        }

        return null;
    }

    public function onReleaseUsing(Player $player, array &$returnedItems): ItemUseResult {
        if ($this->isCharged()) {
            return ItemUseResult::SUCCESS;
        }

        return ItemUseResult::FAIL;
    }

    private function findAmmo(Player $player): ?Item {
        $offhand = $player->getOffHandInventory()->getItem(0);
        if ($this->isValidHeldProjectile($offhand)) {
            return $offhand;
        }

        foreach ($player->getInventory()->getContents() as $item) {
            if ($this->isValidProjectile($item)) {
                return $item;
            }
        }

        if ($player->isCreative()) {
            return VanillaItems::ARROW();
        }

        return null;
    }

    private function isValidProjectile(Item $item): bool {
        return $item instanceof Arrow;
    }

    private function isValidHeldProjectile(Item $item): bool {
        return $item instanceof Arrow || $item instanceof FireworkRocket;
    }

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

        if (!$player->isCreative()) {
            $ammo->setCount($ammo->getCount() - 1);
        }

        return true;
    }

    private function performShooting(Player $player, Vector3 $directionVector, array &$returnedItems): void {
        $chargedProjectiles = $this->getChargedProjectiles();
        if (empty($chargedProjectiles)) {
            return;
        }

        $this->clearChargedProjectiles();

        $isMultishot = count($chargedProjectiles) > 1;
        $angles = $isMultishot ? [-10.0, 0.0, 10.0] : [0.0];

        $location = $player->getLocation();

        foreach ($chargedProjectiles as $index => $projectileItem) {
            $angle = $angles[$index] ?? 0.0;

            $power = ($projectileItem instanceof FireworkRocket)
                ? self::FIREWORK_POWER
                : self::ARROW_POWER;

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

            if ($isMultishot && $index !== 1 && $projectileEntity instanceof ArrowEntity) {
                $projectileEntity->setPickupMode(ArrowEntity::PICKUP_CREATIVE);
            }

            $ev = new ProjectileLaunchEvent($projectileEntity);
            $ev->call();
            if ($ev->isCancelled()) {
                $projectileEntity->flagForDespawn();
                continue;
            }

            $player->getWorld()->addEntity($projectileEntity);
        }

        $player->getWorld()->addSound($player->getPosition(), new CrossbowShootSound());

        $durabilityUse = $this->getDurabilityUseForProjectile($chargedProjectiles[0] ?? null);
        $this->applyDamage($durabilityUse);
    }

    private function createProjectileEntity(
        Player $player,
        Item $projectileItem,
        Location $location,
        Vector3 $directionVector,
        float $power,
        float $angleOffset
    ): ?Projectile {
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
                class_exists('\pocketmine\item\PotionArrow') && $projectileItem instanceof \pocketmine\item\PotionArrow
            );

            $arrowEntity->setMotion($direction->normalize()->multiply($power));

            return $arrowEntity;
        }

        return null;
    }

    private function rotateVectorByAngle(Vector3 $direction, float $angleDegrees, Player $player): Vector3 {
        if (abs($angleDegrees) < 0.001) {
            return $direction;
        }

        $angleRadians = deg2rad($angleDegrees);

        $cos = cos($angleRadians);
        $sin = sin($angleRadians);

        return new Vector3(
            $direction->x * $cos - $direction->z * $sin,
            $direction->y,
            $direction->x * $sin + $direction->z * $cos
        );
    }

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
