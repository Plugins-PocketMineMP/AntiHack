<?php

/*
 *    _         _   _                  _
 *   /_\  _ __ | |_(_) /\  /\__ _  ___| | __
 *  //_\\| '_ \| __| |/ /_/ / _` |/ __| |/ /
 * /  _  \ | | | |_| / __  / (_| | (__|   <
 * \_/ \_/_| |_|\__|_\/ /_/ \__,_|\___|_|\_\
 *
 * Copyright (C) 2020 alvin0319
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);
namespace alvin0319\AntiHack;

use pocketmine\entity\Effect;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

class AntiHack extends PluginBase implements Listener{

	protected $clickData = [];

	protected $breakData = [];

	protected $loggerType;

	public function onEnable() : void{
		$this->saveResource("config.yml");
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->loggerType = $this->getConfig()->getNested("logger-type", "debug");

		if(!in_array($this->loggerType, ["debug", "notice", "info", "alert", "error"])){
			$this->loggerType = "debug";
			$this->getLogger()->error("Invalid logger type given");
		}
	}

	public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
		$packet = $event->getPacket();
		$player = $event->getPlayer();

		if($packet instanceof InventoryTransactionPacket){ // this is cps..
			if($packet->transactionType === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY){
				if($packet->trData->actionType === InventoryTransactionPacket::USE_ITEM_ON_ENTITY_ACTION_ATTACK){
					if(isset($this->clickData[$player->getName()])){
						$lastTime = $this->clickData[$player->getName()];
						$expectTime = abs(0.2 + (((1 / floatval($this->getConfig()->getNested("click-per-seconds", 0.6)) * 20) + 0.5) / $lastTime) ^ 2 * 0.8); // mcbe's attack cooldown is 0.6
						$expectTime -= 0.5; // too terrible
						$actualTime = microtime(true) - $lastTime;

						if($player->hasEffect(Effect::HASTE)){
							$expectTime *= 1 - (0.2 * $player->getEffect(Effect::HASTE)->getEffectLevel());
						}

						if($expectTime > $actualTime){
							$event->setCancelled();
							$player->sendPopup($this->getConfig()->getNested("cps-message", "Your cps is too high!"));
						}else{
							$this->clickData[$player->getName()] = microtime(true);
						}
					}else{
						$this->clickData[$player->getName()] = microtime(true);
					}
				}
			}
		}
	}

	public function onPlayerQuit(PlayerQuitEvent $event) : void{
		$player = $event->getPlayer();

		if(isset($this->clickData[$player->getName()])){
			unset($this->clickData[$player->getName()]);
		}
		if(isset($this->breakData[$player->getName()])){
			unset($this->breakData[$player->getName()]);
		}
	}

	// PMMP source: https://github.com/pmmp/AntiInstaBreak/blob/master/src/pmmp/AntiInstaBreak/Main.php
	public function onPlayerInteract(PlayerInteractEvent $event) : void{
		if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
			$this->breakData[$event->getPlayer()->getName()] = floor(microtime(true) * 20);
		}
	}

	public function onBlockBreak(BlockBreakEvent $event) : void{
		if(!$event->getInstaBreak()){
			do{
				$player = $event->getPlayer();
				if(!isset($this->breakData[$name = $player->getName()])){
					$this->getLogger()->{$this->loggerType}("Player " . $name . " tried to break a block without a start-break action");
					$event->setCancelled();
					break;
				}
				$target = $event->getBlock();
				$item = $event->getItem();
				$expectedTime = ceil($target->getBreakTime($item) * 20);

				if($player->hasEffect(Effect::HASTE)){
					$expectedTime *= 1 - (0.2 * $player->getEffect(Effect::HASTE)->getEffectLevel());
				}
				if($player->hasEffect(Effect::MINING_FATIGUE)){
					$expectedTime *= 1 + (0.3 * $player->getEffect(Effect::MINING_FATIGUE)->getEffectLevel());
				}

				$expectedTime -= 1; //1 tick compensation
				$actualTime = ceil(microtime(true) * 20) - $this->breakData[$name = $player->getName()];
				if($expectedTime > $actualTime){
					$this->getLogger()->{$this->loggerType}("Player " . $name . " tried to break a block too fast, expected $expectedTime ticks, got $actualTime ticks");
					$event->setCancelled();
					break;
				}
				unset($this->breakData[$name]);
			}while(false);
		}
	}

	public function onEntityDamage(EntityDamageByEntityEvent $event) : void{
		$entity = $event->getEntity();
		$damager = $event->getDamager();
		if($damager instanceof Player){
			$dis = $damager->distance($entity);
			if($dis > 5){
				$event->setCancelled();
				$damager->sendPopup($this->getConfig()->getNested("distance-message", "Your click distance is too far!"));
			}

			if(boolval($this->getConfig()->getNested("enable-back", false))){
				$alpha = abs($damager->yaw - $entity->yaw) / 2;
				if(!($alpha >= 50 and $alpha <= 140)){
					$event->setCancelled();
					$damager->sendPopup("You can't hit other entity that you can't show that entity.");
				}
			}
		}
	}
}