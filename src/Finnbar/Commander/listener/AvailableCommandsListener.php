<?php

namespace Finnbar\Commander\listener;

use Exception;
use Finnbar\Commander\Commander;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;

class AvailableCommandsListener implements Listener
{

    public function onDataPacketSend(DataPacketSendEvent $event)
    {
        foreach ($event->getTargets() as $target) {
            foreach ($event->getPackets() as $pk) {
                if ($pk instanceof AvailableCommandsPacket) {
                    foreach ($pk->commandData as $commandData) {
                        if (!$target->getPlayer()) {
                            throw new Exception("Sent AvailableCommandsPacket too early!");
                        }
                        $cmd = Commander::getCommand($commandData->name);
                        if (!$cmd) {
                            continue;
                        }
                        Commander::addOverloads($cmd, $target->getPlayer(), $commandData);
                    }
                }
            }
        }
    }
}
