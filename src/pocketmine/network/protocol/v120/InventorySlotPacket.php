<?php

namespace pocketmine\network\protocol\v120;

use pocketmine\network\protocol\{Info120, Info};
use pocketmine\network\protocol\PEPacket;

class InventorySlotPacket extends PEPacket {
	
	const NETWORK_ID = Info120::INVENTORY_SLOT_PACKET;
	const PACKET_NAME = "INVENTORY_SLOT_PACKET";
	
	/** @var integer */
	public $containerId;
	/** @var integer */
	public $slot;
	/** @var Item */
	public $item = null;
	
	public function decode($playerProtocol) {}

	public function encode($playerProtocol) {
		$this->reset($playerProtocol);
		$this->putUnsignedVarInt($this->containerId);
		$this->putUnsignedVarInt($this->slot);
		$this->putSlot($this->item, $playerProtocol);
	}
}