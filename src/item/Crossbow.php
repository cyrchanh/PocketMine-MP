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

class Crossbow extends Tool implements Releasable{

private const BASE_CHARGE_DURATION_TICKS = 25;
private const ARROW_POWER = 3.15;

public function getMaxDurability() : int{
return 465;
}

public function getChargeDurationTicks() : int{
return self::BASE_CHARGE_DURATION_TICKS;
}

public function isCharged() : bool{
$tag = $this->getNamedTag();
$projectiles = $tag->getListTag("chargedProjectiles");
return $projectiles !== null && $projectiles->count() > 0;
}

public function getChargedProjectiles() : array{
$tag = $this->getNamedTag();
$projectiles = $tag->getListTag("chargedProjectiles");
if($projectiles === null){
return [];
}
$items = [];
foreach($projectiles as $projectileTag){
if($projectileTag instanceof CompoundTag){
$item = Item::nbtDeserialize($projectileTag);
if(!$item->isNull()){
$items[] = $item;
}
}
}
return $items;
}

public function setChargedProjectiles(array $projectiles) : self{
$tag = $this->getNamedTag();
if(count($projectiles) === 0){
$tag->removeTag("chargedProjectiles");
}else{
$list = new ListTag();
foreach($projectiles as $item){
$list->push($item->nbtSerialize());
}
$tag->setTag("chargedProjectiles", $list);
}
$this->setNamedTag($tag);
return $this;
}

public function clearChargedProjectiles() : self{
return $this->setChargedProjectiles([]);
}

public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
if($this->isCharged()){
$this->performShooting($player, $directionVector);
return ItemUseResult::SUCCESS;
}

// Return NONE (not SUCCESS) so the framework doesn't send an inventory
// update that would cause the client to restart the charging animation.
// This matches how Bow behaves — it never overrides onClickAir at all.
return ItemUseResult::NONE;
}

public function onReleaseUsing(Player $player, array &$returnedItems) : ItemUseResult{
if($this->isCharged()){
return ItemUseResult::FAIL;
}

$ticksUsed = $player->getItemUseDuration();
if($ticksUsed >= $this->getChargeDurationTicks()){
if($this->tryLoadProjectile($player)){
$player->getWorld()->addSound($player->getPosition(), new CrossbowLoadingEndSound());
return ItemUseResult::SUCCESS;
}
}

return ItemUseResult::FAIL;
}

private function findAmmo(Player $player) : ?Item{
$offhand = $player->getOffHandInventory()->getItem(0);
if($offhand instanceof Arrow){
return $offhand;
}

foreach($player->getInventory()->getContents() as $item){
if($item instanceof Arrow){
return $item;
}
}

if($player->isCreative()){
return VanillaItems::ARROW();
}

return null;
}

private function tryLoadProjectile(Player $player) : bool{
$ammo = $this->findAmmo($player);
if($ammo === null){
return false;
}

$projectileCopy = clone $ammo;
$projectileCopy->setCount(1);
$this->setChargedProjectiles([$projectileCopy]);

if(!$player->isCreative()){
$ammoToRemove = clone $ammo;
$ammoToRemove->setCount(1);
$player->getInventory()->removeItem($ammoToRemove);
}

return true;
}

private function performShooting(Player $player, Vector3 $directionVector) : void{
$chargedProjectiles = $this->getChargedProjectiles();
if(count($chargedProjectiles) === 0){
return;
}

$this->clearChargedProjectiles();
$location = $player->getLocation();

foreach($chargedProjectiles as $projectileItem){
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
if($ev->isCancelled()){
$arrowEntity->flagForDespawn();
continue;
}

$arrowEntity->spawnToAll();
}

$player->getWorld()->addSound($player->getPosition(), new CrossbowShootSound());

if(!$player->isCreative()){
$this->applyDamage(1);
}
}

public function canStartUsingItem(Player $player) : bool{
return !$this->isCharged() && $this->findAmmo($player) !== null;
}

public function getFuelTime() : int{
return 0;
}
}