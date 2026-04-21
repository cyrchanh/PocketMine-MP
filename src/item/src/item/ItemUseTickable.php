<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\player\Player;

interface ItemUseTickable{
  
	public function onUsingTick(Player $player, int $ticksUsed, array &$returnedItems) : ?ItemUseResult;
  
}
