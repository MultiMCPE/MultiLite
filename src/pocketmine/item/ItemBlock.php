<?php

/*
 *
 *  _____            _               _____           
 * / ____|          (_)             |  __ \          
 *| |  __  ___ _ __  _ ___ _   _ ___| |__) | __ ___  
 *| | |_ |/ _ \ '_ \| / __| | | / __|  ___/ '__/ _ \ 
 *| |__| |  __/ | | | \__ \ |_| \__ \ |   | | | (_) |
 * \_____|\___|_| |_|_|___/\__, |___/_|   |_|  \___/ 
 *                         __/ |                    
 *                        |___/                     
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author GenisysPro
 * @link https://github.com/GenisysPro/GenisysPro
 *
 *
*/

namespace pocketmine\item;

use pocketmine\block\Block;

/**
 * Class used for Items that can be Blocks
 */
class ItemBlock extends Item{
	/**
	 * ItemBlock constructor.
	 *
	 * @param Block $block
	 * @param int   $meta
	 * @param int   $count
	 */
	public function __construct(Block $block, $meta = 0, int $count = 1){
		$this->block = $block;
		parent::__construct($block->getId(), $block->getDamage(), $count, $block->getName());
	}

	public function setDamage(int $meta){
		$this->block->setDamage($meta !== -1 ? $meta & 0xf : 0);
		return parent::setDamage($meta);
	}

	/**
	 * @return Block
	 */
	public function getBlock() : Block{
		return $this->block;
	}

}