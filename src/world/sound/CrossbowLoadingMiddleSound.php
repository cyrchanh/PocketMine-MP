<?php

declare(strict_types=1);

namespace pocketmine\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

class CrossbowLoadingMiddleSound implements Sound {
    public function encode(Vector3 $pos): array {
        return [LevelSoundEventPacket::nonActorSound(
            LevelSoundEvent::CROSSBOW_LOADING_MIDDLE,
            $pos,
            false
        )];
    }
}

