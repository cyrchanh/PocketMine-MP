<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use pocketmine\world\sound\CrossbowLoadingEndSound;
use pocketmine\world\sound\CrossbowShootSound;

class Crossbow extends Tool implements Releasable
{

    private const BASE_CHARGE_DURATION_TICKS = 25;
    private const ARROW_POWER = 3.15;

    /**
     * Track charge start time per player, independent of isUsingItem flag
     * which gets reset by inventory sync.
     * @var array<string, int>
     */
    private static array $chargeStartTicks = [];

    public function getMaxDurability(): int
    {
        return 465;
    }

    public function getChargeDurationTicks(): int
    {
        return self::BASE_CHARGE_DURATION_TICKS;
    }

    public function isCharged(): bool
    {
        $tag = $this->getNamedTag();
        $projectiles = $tag->getListTag("chargedProjectiles");
        return $projectiles !== null && $projectiles->count() > 0;
    }

    public function getChargedProjectiles(): array
    {
        $tag = $this->getNamedTag();
        $projectiles = $tag->getListTag("chargedProjectiles");
        if ($projectiles === null) {
            return [];
        }
        $items = [];
        foreach ($projectiles as $projectileTag) {
            if ($projectileTag instanceof CompoundTag) {
                $item = Item::nbtDeserialize($projectileTag);
                if (!$item->isNull()) {
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    public function setChargedProjectiles(array $projectiles): self
    {
        $tag = $this->getNamedTag();
        if (count($projectiles) === 0) {
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

    public function clearChargedProjectiles(): self
    {
        return $this->setChargedProjectiles([]);
    }

    public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems): ItemUseResult
    {
        $name = $player->getName();

        if ($this->isCharged()) {
            unset(self::$chargeStartTicks[$name]);
            $this->performShooting($player, $directionVector);
            $player->getServer()->getLogger()->info("[CROSSBOW] Fired!");
            return ItemUseResult::SUCCESS;
        }

        // Only record the charge start on the FIRST click — don't restart on repeated clicks
        if (!isset(self::$chargeStartTicks[$name])) {
            self::$chargeStartTicks[$name] = $player->getServer()->getTick();
            $player->getServer()->getLogger()->info("[CROSSBOW] Charge started at tick " . self::$chargeStartTicks[$name]);
        } else {
            $elapsed = $player->getServer()->getTick() - self::$chargeStartTicks[$name];
            $player->getServer()->getLogger()->info("[CROSSBOW] Charge continuing, elapsed=$elapsed ticks");

            // Auto-load if we've been charging long enough (client sent another CLICK_AIR
// but we've already reached the charge threshold)
            if ($elapsed >= $this->getChargeDurationTicks()) {
                if ($this->tryLoadProjectile($player)) {
                    $player->getServer()->getLogger()->info("[CROSSBOW] Auto-loaded (charge complete during hold)!");
                    $player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingEndSound());
                    unset(self::$chargeStartTicks[$name]);
                    return ItemUseResult::SUCCESS;
                }
            }
        }

        return ItemUseResult::NONE;
    }

    public function onReleaseUsing(Player $player, array &$returnedItems): ItemUseResult
    {
        $name = $player->getName();

        if ($this->isCharged()) {
            unset(self::$chargeStartTicks[$name]);
            return ItemUseResult::FAIL;
        }

        $startTick = self::$chargeStartTicks[$name] ?? null;
        unset(self::$chargeStartTicks[$name]);

        if ($startTick === null) {
            $player->getServer()->getLogger()->info("[CROSSBOW] onReleaseUsing: no charge start recorded");
            return ItemUseResult::FAIL;
        }

        $ticksUsed = $player->getServer()->getTick() - $startTick;
        $player->getServer()->getLogger()->info("[CROSSBOW] onReleaseUsing: ticksUsed=$ticksUsed (from our own tracking), needed=" . $this->getChargeDurationTicks());

        if ($ticksUsed >= $this->getChargeDurationTicks()) {
            if ($this->tryLoadProjectile($player)) {
                $player->getServer()->getLogger()->info("[CROSSBOW] Loaded projectile!");
                $player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingEndSound());
                return ItemUseResult::SUCCESS;
            }
            $player->getServer()->getLogger()->info("[CROSSBOW] No ammo found!");
        } else {
            $player->getServer()->getLogger()->info("[CROSSBOW] Not charged long enough ($ticksUsed < " . $this->getChargeDurationTicks() . ")");
        }

        return ItemUseResult::FAIL;
    }

    private function findAmmo(Player $player): ?Item
    {
        $offhand = $player->getOffHandInventory()->getItem(0);
        if ($offhand instanceof Arrow) {
            return $offhand;
        }

        foreach ($player->getInventory()->getContents() as $item) {
            if ($item instanceof Arrow) {
                return $item;
            }
        }

        if ($player->isCreative()) {
            return VanillaItems::ARROW();
        }

        return null;
    }

    private function tryLoadProjectile(Player $player): bool
    {
        $ammo = $this->findAmmo($player);
        if ($ammo === null) {
            return false;
        }

        $projectileCopy = clone $ammo;
        $projectileCopy->setCount(1);
        $this->setChargedProjectiles([$projectileCopy]);

        if (!$player->isCreative()) {
            $ammoToRemove = clone $ammo;
            $ammoToRemove->setCount(1);
            $player->getInventory()->removeItem($ammoToRemove);
        }

        return true;
    }

    private function performShooting(Player $player, Vector3 $directionVector): void
    {
        $chargedProjectiles = $this->getChargedProjectiles();
        if (count($chargedProjectiles) === 0) {
            return;
        }

        $this->clearChargedProjectiles();
        $location = $player->getLocation();

        foreach ($chargedProjectiles as $projectileItem) {
            $arrowEntity = new ArrowEntity(
                Location::fromObject(
                    $player->getEyePos(),
                    $player->getWorld(),
                    ($location->yaw > 180 ? 360 : 0) - $location->yaw,
                    -$location->pitch
                ),
                $player,
                false
            );

            $arrowEntity->setMotion($directionVector->normalize()->multiply(self::ARROW_POWER));

            $ev = new ProjectileLaunchEvent($arrowEntity);
            $ev->call();
            if ($ev->isCancelled()) {
                $arrowEntity->flagForDespawn();
                continue;
            }

            $arrowEntity->spawnToAll();
        }

        $player->getWorld()->addSound($player->getPosition(), new CrossbowShootSound());

        if (!$player->isCreative()) {
            $this->applyDamage(1);
        }
    }

    public function canStartUsingItem(Player $player): bool
    {
        // If already tracking a charge, keep returning true so setUsingItem(true) is maintained
        if (isset(self::$chargeStartTicks[$player->getName()])) {
            return true;
        }
        return !$this->isCharged() && $this->findAmmo($player) !== null;
    }

    public function getFuelTime(): int
    {
        return 0;
    }
}
