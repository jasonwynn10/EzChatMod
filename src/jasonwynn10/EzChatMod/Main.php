<?php
declare(strict_types=1);
namespace jasonwynn10\EzChatMod;

use jasonwynn10\EzChatMod\mute\MuteEntry;
use jasonwynn10\EzChatMod\mute\MuteList;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener {

	/** @var MuteList $mutedList */
	protected $mutedList;
	/** @var string[][] $messageDataStore */
	protected $messageDataStore = [];
	/** @var int[] $messageTimeout */
	protected $messageTimeout = [];

	public function onEnable() {
		$this->saveDefaultConfig();
		$this->mutedList = new MuteList($this->getDataFolder()."muted-players.txt");
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	protected function trackMessageData(Player $player, string $message) {
		if(!isset($this->messageDataStore[$player->getName()]))
			$this->messageDataStore[$player->getName()] = [];
		$this->messageDataStore[$player->getName()][] = $message;
		// TODO: shift messages after duplicate check limit reached

		$this->messageTimeout[$player->getName()] = (new \DateTime())->getTimestamp();
	}

	// API

	public function mutePlayer(string $target, string $reason, \DateTime $expires = null, string $source = null) : MuteEntry {
		$seconds = (int)$this->getConfig()->getNested("Mute Settings.Timeout Length", 10);

		$entry = new MuteEntry($target);
		$entry->setSource($source ?? $entry->getSource());
		$entry->setExpires($expires ?? (new \DateTime())->add(\DateInterval::createFromDateString($seconds." seconds")));
		$entry->setReason($reason ?? $entry->getReason());

		$this->mutedList->add($entry);
		return $entry;
	}

	public function unMutePlayer(string $target) : void {
		$this->mutedList->remove($target);
	}

	public function isMuted(string $target) : bool {
		return $this->mutedList->isBanned($target);
	}

	public function isMessageDuplicated(string $target, string $message, int $lookBack = 1) : bool {
		if(!isset($this->messageDataStore[$target]))
			$this->messageDataStore[$target] = [];
		if(!isset($this->messageDataStore[$target][count($this->messageDataStore[$target]) - $lookBack]))
			return false;

		for($backCount = 1, $same = true; $backCount <= $lookBack, !$same; ++$backCount) {
			$same = $this->messageDataStore[$target][count($this->messageDataStore[$target]) - $lookBack] === $message;
		}

		return $same;
	}

	public function isMessageTimeout(string $target, int $seconds) : bool {
		if(!isset($this->messageTimeout[$target]))
			$this->messageTimeout[$target] = 0;
		return $this->messageTimeout[$target] > (new \DateTime())->getTimestamp() - $seconds;
	}

	public function isInConversation(string $target) : bool {
		if(!isset($this->messageTimeout[$target]))
			$this->messageTimeout[$target] = 0;

		$participants = array_filter($this->messageTimeout,
			function(int $timestamp) use($target) {
				return $timestamp >= $this->messageTimeout[$target];
			}
		);

		return count($participants) > 0 and !$this->isMessageTimeout($target, 1);
	}

	// EVENTS

	public function onChat(PlayerChatEvent $event) {
		$player = $event->getPlayer();

		if($this->isMuted($player->getName())) {
			$event->setCancelled();
			$player->sendMessage(TextFormat::RED."You are Muted");
			return;
		}

		$message = $event->getMessage();

		$this->trackMessageData($player, $message);

		if($this->getConfig()->getNested("Spam Tracking Method.Caps Lock", true) and $message === strtoupper($message)) {
			$reason = "Caps Lock Spam";
			switch(strtolower($this->getConfig()->getNested("Punishments.Caps Lock", "mute"))) {
				case "single-mute":
					$event->setCancelled();
					$player->sendMessage(TextFormat::RED."Message Blocked for ". $reason);
					return;
				break;
				case "mute":
					$this->mutePlayer($player->getName(), $reason);
					$event->setCancelled();
					$player->sendMessage(TextFormat::RED."Message Blocked for ". $reason);
					return;
				break;
				case "kick":
					$player->kick($reason, false);
					$event->setCancelled();
					return;
				break;
				case "ban":
					$this->getServer()->getNameBans()->addBan($player->getName(), $reason);
					$player->kick($reason, false);
					$event->setCancelled();
					return;
				break;
				case "ip-ban":
					$this->getServer()->getIPBans()->addBan($player->getName(), $reason);
					$player->kick($reason, false);
					$event->setCancelled();
					return;
				break;
				default:
				case "none":
					// do nothing
				break;
			}
		}

		if($this->getConfig()->getNested("Spam Tracking Method.Duplicates", true)) {
			$duplicateCheckBack = (int)$this->getConfig()->getNested("Duplicate Settings.Maximum Duplicates", 3);
			if($this->isMessageDuplicated($player->getName(), $message, $duplicateCheckBack)) {
				$reason = "Duplicate Message Spam";
				switch(strtolower($this->getConfig()->getNested("Punishments.Duplicates", "mute"))) {
					case "single-mute":
						$event->setCancelled();
						$player->sendMessage(TextFormat::RED."Message Blocked for ". $reason);
						return;
					break;
					case "mute":
						$this->mutePlayer($player->getName(), $reason);
						$event->setCancelled();
						$player->sendMessage(TextFormat::RED."Message Blocked for ". $reason);
						return;
					break;
					case "kick":
						$player->kick($reason, false);
						$event->setCancelled();
						return;
					break;
					case "ban":
						$this->getServer()->getNameBans()->addBan($player->getName(), $reason);
						$player->kick($reason, false);
						$event->setCancelled();
						return;
					break;
					case "ip-ban":
						$this->getServer()->getIPBans()->addBan($player->getName(), $reason);
						$player->kick($reason, false);
						$event->setCancelled();
						return;
					break;
					default:
					case "none":
						// do nothing
					break;
				}
			}
		}

		if($this->getConfig()->getNested("Spam Tracking Method.Message Timeout", true)) {
			$seconds = (int)$this->getConfig()->getNested("Message Timeout Settings.Timeout Length", 1);
			if($this->isMessageTimeout($player->getName(), $seconds)) {
				$reason = "Message Timeout";
				switch(strtolower($this->getConfig()->getNested("Punishments.Message Timeout", "single-mute"))) {
					case "single-mute":
						$event->setCancelled();
						$player->sendMessage(TextFormat::RED."Message Blocked for ". $reason);
						return;
					break;
					case "mute":
						$this->mutePlayer($player->getName(), $reason);
						$event->setCancelled();
						$player->sendMessage(TextFormat::RED."Message Blocked for ". $reason);
						return;
					break;
					case "kick":
						$player->kick($reason, false);
						$event->setCancelled();
						return;
					break;
					case "ban":
						$this->getServer()->getNameBans()->addBan($player->getName(), $reason);
						$player->kick($reason, false);
						$event->setCancelled();
						return;
					break;
					case "ip-ban":
						$this->getServer()->getIPBans()->addBan($player->getName(), $reason);
						$player->kick($reason, false);
						$event->setCancelled();
						return;
					break;
					default:
					case "none":
						// do nothing
					break;
				}
			}
		}

		if($this->getConfig()->getNested("Spam Tracking Method.Conversation", true) and !$this->isInConversation($player->getName())) {
			$reason = "Typing to fast for a conversation";
			switch(strtolower($this->getConfig()->getNested("Punishments.Conversation", "single-mute"))) {
				case "single-mute":
					$event->setCancelled();
					$player->sendMessage(TextFormat::RED."Message Blocked for ". $reason);
					return;
				break;
				case "mute":
					$this->mutePlayer($player->getName(), $reason);
					$event->setCancelled();
					$player->sendMessage(TextFormat::RED."Message Blocked for ". $reason);
					return;
				break;
				case "kick":
					$player->kick($reason, false);
					$event->setCancelled();
					return;
				break;
				case "ban":
					$this->getServer()->getNameBans()->addBan($player->getName(), $reason);
					$player->kick($reason, false);
					$event->setCancelled();
					return;
				break;
				case "ip-ban":
					$this->getServer()->getIPBans()->addBan($player->getName(), $reason);
					$player->kick($reason, false);
					$event->setCancelled();
					return;
				break;
				default:
				case "none":
					// do nothing
				break;
			}
		}
	}
}