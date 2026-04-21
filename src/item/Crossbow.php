<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;

class Crossbow extends Tool implements Releasable
{

    private const BASE_CHARGE_DURATION_TICKS = 25;
    private const CHARGE_REDUCTION_PER_LEVEL = 5;
    private const ARROW_POWER = 3.15;
    private const CLICK_GAP_THRESHOLD = 5;
    private const MULTISHOT_SPREAD_DEGREES = 10.0;

    private static array $chargeStartTicks = [];
    private static array $justFired = [];
    private static array $loadedDuringHold = [];

    public function getMaxDurability(): int
    {
        return 465;
    }

    public function getChargeDurationTicks(): int
    {
        $quickChargeLevel = $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE());
        return max(0, self::BASE_CHARGE_DURATION_TICKS - ($quickChargeLevel * self::CHARGE_REDUCTION_PER_LEVEL));
    }

    public function isCharged(): bool
    {
        return $this->getNamedTag()->getCompoundTag("chargedItem") !== null;
    }

    public function setChargedItem(?Item $item): self
    {
        $tag = $this->getNamedTag();
        if ($item === null || $item->isNull()) {
            $tag->removeTag("chargedItem");
        } else {
            $tag->setTag("chargedItem", $item->nbtSerialize());
        }
        $this->setNamedTag($tag);
        return $this;
    }

    public function clearChargedItem(): self
    {
        return $this->setChargedItem(null);
    }

    private function playCrossbowSound(Player $player, string $soundName, float $volume = 1.0, float $pitch = 1.0): void
    {
        $pos = $player->getPosition();
        $pk = PlaySoundPacket::create($soundName, $pos->x, $pos->y, $pos->z, $volume, $pitch);
        $player->getNetworkSession()->sendDataPacket($pk);
        foreach ($player->getViewers() as $viewer) {
            $viewer->getNetworkSession()->sendDataPacket($pk);
        }
    }

    private function getLoadingStartSound(): string
    {
        $quickChargeLevel = $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE());
        return match ($quickChargeLevel) {
            1 => "item.crossbow.quick_charge.1",
            2 => "item.crossbow.quick_charge.2",
            3 => "item.crossbow.quick_charge.3",
            default => "item.crossbow.loading_start",
        };
    }

    public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems): ItemUseResult
    {
        $name = $player->getName();
        $currentTick = $player->getServer()->getTick();

        if ($this->isCharged()) {
            if (isset(self::$loadedDuringHold[$name])) {
                $gap = $currentTick - self::$loadedDuringHold[$name];
                if ($gap <= self::CLICK_GAP_THRESHOLD) {
                    self::$loadedDuringHold[$name] = $currentTick;
                    return ItemUseResult::FAIL;
                }
                unset(self::$loadedDuringHold[$name]);
            }

            unset(self::$chargeStartTicks[$name]);
            self::$justFired[$name] = true;
            $this->performShooting($player, $directionVector);
            $player->getInventory()->setItemInHand($this);
            return ItemUseResult::SUCCESS;
        }

        if (!isset(self::$chargeStartTicks[$name])) {
            if ($this->findAmmo($player) === null) {
                return ItemUseResult::FAIL;
            }
            self::$chargeStartTicks[$name] = $currentTick;
            $this->playCrossbowSound($player, $this->getLoadingStartSound());
            return ItemUseResult::NONE;
        }

        $elapsed = $currentTick - self::$chargeStartTicks[$name];

        if ($elapsed >= $this->getChargeDurationTicks()) {
            if ($this->tryLoadProjectile($player)) {
                $this->playCrossbowSound($player, "item.crossbow.loading_end");
                unset(self::$chargeStartTicks[$name]);
                self::$loadedDuringHold[$name] = $currentTick;
                $player->getInventory()->setItemInHand($this);
                return ItemUseResult::SUCCESS;
            }
            unset(self::$chargeStartTicks[$name]);
            return ItemUseResult::FAIL;
        }

        return ItemUseResult::FAIL;
    }

    public function onReleaseUsing(Player $player, array &$returnedItems): ItemUseResult
    {
        $name = $player->getName();

        unset(self::$loadedDuringHold[$name]);

        if ($this->isCharged()) {
            unset(self::$chargeStartTicks[$name]);
            return ItemUseResult::FAIL;
        }

        $startTick = self::$chargeStartTicks[$name] ?? null;
        unset(self::$chargeStartTicks[$name]);

        if ($startTick === null) {
            return ItemUseResult::FAIL;
        }

        $ticksUsed = $player->getServer()->getTick() - $startTick;

        if ($ticksUsed >= $this->getChargeDurationTicks()) {
            if ($this->tryLoadProjectile($player)) {
                $this->playCrossbowSound($player, "item.crossbow.loading_end");
                $player->getInventory()->setItemInHand($this);
                return ItemUseResult::SUCCESS;
            }
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
        $this->setChargedItem($projectileCopy);

        if (!$player->isCreative()) {
            $ammoToRemove = clone $ammo;
            $ammoToRemove->setCount(1);
            $player->getInventory()->removeItem($ammoToRemove);
        }
        return true;
    }

    private function createArrowEntity(Player $player, Vector3 $directionVector, bool $isMainArrow): ArrowEntity
    {
        $location = $player->getLocation();

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

        // Piercing enchantment: set pierce level on the arrow entity
        $piercingLevel = $this->getEnchantmentLevel(VanillaEnchantments::PIERCING());
        if ($piercingLevel > 0) {
            $arrowEntity->setPierceLevel($piercingLevel);
        }

        // Multishot extra arrows cannot be picked up (only the center arrow can)
        if (!$isMainArrow) {
            $arrowEntity->setPickupMode(ArrowEntity::PICKUP_CREATIVE);
        }

        return $arrowEntity;
    }

    private function rotateDirectionY(Vector3 $direction, float $angleDegrees): Vector3
    {
        if (abs($angleDegrees) < 0.001) {
            return $direction;
        }

        $angleRad = deg2rad($angleDegrees);
        $cos = cos($angleRad);
        $sin = sin($angleRad);

        return new Vector3(
            $direction->x * $cos + $direction->z * $sin,
            $direction->y,
            -$direction->x * $sin + $direction->z * $cos
        );
    }

    private function performShooting(Player $player, Vector3 $directionVector): void
    {
        if (!$this->isCharged()) {
            return;
        }

        $this->clearChargedItem();

        // Multishot: fire 3 arrows at 0°, +10°, -10° spread
        $multishotLevel = $this->getEnchantmentLevel(VanillaEnchantments::MULTISHOT());
        $hasMultishot = $multishotLevel > 0;
        $projectileCount = $hasMultishot ? 3 : 1;

        $angles = $hasMultishot
            ? [0.0, self::MULTISHOT_SPREAD_DEGREES, -self::MULTISHOT_SPREAD_DEGREES]
            : [0.0];

        for ($i = 0; $i < $projectileCount; $i++) {
            $shotDirection = $this->rotateDirectionY($directionVector, $angles[$i]);
            $isMainArrow = ($i === 0);

            $arrowEntity = $this->createArrowEntity($player, $shotDirection, $isMainArrow);

            $ev = new ProjectileLaunchEvent($arrowEntity);
            $ev->call();
            if ($ev->isCancelled()) {
                $arrowEntity->flagForDespawn();
                continue;
            }

            $arrowEntity->spawnToAll();
        }

        $this->playCrossbowSound($player, "item.crossbow.shoot");

        if (!$player->isCreative()) {
            // Multishot uses 3 durability per shot, normal uses 1
            $durabilityUse = $hasMultishot ? 3 : 1;
            $this->applyDamage($durabilityUse);
        }
    }

    public function canStartUsingItem(Player $player): bool
    {
        $name = $player->getName();

        if (isset(self::$justFired[$name])) {
            unset(self::$justFired[$name]);
            return false;
        }

        if ($this->isCharged()) {
            return false;
        }

        if (isset(self::$chargeStartTicks[$name])) {
            return true;
        }

        return $this->findAmmo($player) !== null;
    }

    public function getFuelTime(): int
    {
        return 0;
    }
}
