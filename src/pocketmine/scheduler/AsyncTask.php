<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\scheduler;

use pocketmine\Server;
use pocketmine\utils\MainLogger;

/**
 * Class used to run async tasks in other threads.
 *
 * An AsyncTask does not have its own thread. It is queued into an AsyncPool and executed if there is an async worker
 * with no AsyncTask running. Therefore, an AsyncTask SHOULD NOT execute for more than a few seconds. For tasks that
 * run for a long time or infinitely, start another {@link \pocketmine\Thread} instead.
 *
 * WARNING: Any non-Threaded objects WILL BE SERIALIZED when assigned to members of AsyncTasks or other Threaded object.
 * If later accessed from said Threaded object, you will be operating on a COPY OF THE OBJECT, NOT THE ORIGINAL OBJECT.
 * If you want to store non-serializable objects to access when the task completes, store them using
 * {@link AsyncTask#storeLocal}.
 *
 * WARNING: As of pthreads v3.1.6, arrays are converted to Volatile objects when assigned as members of Threaded objects.
 * Keep this in mind when using arrays stored as members of your AsyncTask.
 *
 * WARNING: Do not call PocketMine-MP API methods from other Threads!!
 */
abstract class AsyncTask extends \Threaded implements \Collectable{
	/**
	 * @var \SplObjectStorage|null
	 * Used to store objects on the main thread which should not be serialized.
	 */
	private static $localObjectStorage;

	/** @var AsyncWorker $worker */
	public $worker = null;

	/** @var \Threaded */
	public $progressUpdates;

	private $result = null;
	private $serialized = false;
	private $cancelRun = false;
	/** @var int */
	private $taskId = null;

	private $crashed = false;

	private $isGarbage = false;

	private $isFinished = false;

	/**
	 * @return bool
	 */
	public function isGarbage() : bool{
		return $this->isGarbage;
	}

	public function setGarbage(){
		$this->isGarbage = true;
	}

	/**
	 * @return bool
	 */
	public function isFinished() : bool{
		return $this->isFinished;
	}

	public function run(){
		$this->result = null;
		$this->isGarbage = false;

		if($this->cancelRun !== true){
			try{
				$this->onRun();
			}catch(\Throwable $e){
				$this->crashed = true;
				$this->worker->handleException($e);
			}
		}

		$this->isFinished = true;
		//$this->setGarbage();
	}

	/**
	 * @return bool
	 */
	public function isCrashed(){
		return $this->crashed;
	}

	/**
	 * @return mixed
	 */
	public function getResult(){
		return $this->serialized ? unserialize($this->result) : $this->result;
	}

	public function cancelRun(){
		$this->cancelRun = true;
	}

	/**
	 * @return bool
	 */
	public function hasCancelledRun(){
		return $this->cancelRun === true;
	}

	/**
	 * @return bool
	 */
	public function hasResult(){
		return $this->result !== null;
	}

	/**
	 * @param mixed $result
	 */
    public function setResult($result){
        $this->result = ($this->serialized = !is_scalar($result)) ? serialize($result) : $result;
	}

	/**
	 * @param $taskId
	 */
	public function setTaskId($taskId){
		$this->taskId = $taskId;
	}

	/**
	 * @return int
	 */
	public function getTaskId(){
		return $this->taskId;
	}

	/**
	 * Gets something into the local thread store.
	 * You have to initialize this in some way from the task on run
	 *
	 * @param string $identifier
	 *
	 * @return mixed
	 */
	public function getFromThreadStore($identifier){
		global $store;
		return ($this->isGarbage() or !isset($store[$identifier])) ? null : $store[$identifier];
	}

	/**
	 * Saves something into the local thread store.
	 * This might get deleted at any moment.
	 *
	 * @param string $identifier
	 * @param mixed  $value
	 */
	public function saveToThreadStore($identifier, $value){
		global $store;
		if(!$this->isGarbage()){
			$store[$identifier] = $value;
		}
	}

	/**
	 * @see AsyncWorker::removeFromThreadStore()
	 *
	 * @param string $identifier
	 */
	public function removeFromThreadStore(string $identifier) : void{
		if($this->worker === null or $this->isGarbage()){
			throw new \BadMethodCallException("Objects can only be removed from AsyncWorker thread-local storage during task execution");
		}
		$this->worker->removeFromThreadStore($identifier);
	}

	/**
	 * Actions to execute when run
	 *
	 * @return void
	 */
	public abstract function onRun();

	/**
	 * Actions to execute when completed (on main thread)
	 * Implement this if you want to handle the data in your AsyncTask after it has been processed
	 *
	 * @param Server $server
	 *
	 * @return void
	 */
	public function onCompletion(Server $server){

	}

	/**
	 * Call this method from {@link AsyncTask#onRun} (AsyncTask execution therad) to schedule a call to
	 * {@link AsyncTask#onProgressUpdate} from the main thread with the given progress parameter.
	 *
	 * @param \Threaded|mixed $progress A Threaded object, or a value that can be safely serialize()'ed.
	 */
	public function publishProgress($progress){
		$this->progressUpdates[] = serialize($progress);
	}

	/**
	 * @internal Only call from AsyncPool.php on the main thread
	 *
	 * @param Server $server
	 */
	public function checkProgressUpdates(Server $server){
		while($this->progressUpdates->count() !== 0){
			$progress = $this->progressUpdates->shift();
			$this->onProgressUpdate($server, unserialize($progress));
		}
	}

	/**
	 * Called from the main thread after {@link AsyncTask#publishProgress} is called.
	 * All {@link AsyncTask#publishProgress} calls should result in {@link AsyncTask#onProgressUpdate} calls before
	 * {@link AsyncTask#onCompletion} is called.
	 *
	 * @param Server          $server
	 * @param \Threaded|mixed $progress The parameter passed to {@link AsyncTask#publishProgress}. If it is not a
	 *                                  Threaded object, it would be serialize()'ed and later unserialize()'ed, as if it
	 *                                  has been cloned.
	 */
	public function onProgressUpdate(Server $server, $progress){

	}

	/**
	 * Saves mixed data in thread-local storage on the parent thread. You may use this to retain references to objects
	 * or arrays which you need to access in {@link AsyncTask#onCompletion} which cannot be stored as a property of
	 * your task (due to them becoming serialized).
	 *
	 * Scalar types can be stored directly in class properties instead of using this storage.
	 *
	 * Objects stored in this storage MUST be retrieved through {@link #fetchLocal} when {@link #onCompletion} is called.
	 * Otherwise, a NOTICE level message will be raised and the reference will be removed after onCompletion exits.
	 *
	 * WARNING: Use this method carefully. It might take a long time before an AsyncTask is completed. PocketMine will
	 * keep a strong reference to objects passed in this method. This may result in a light memory leak. Usually this
	 * does not cause memory failure, but be aware that the object may be no longer usable when the AsyncTask completes.
	 * (E.g. a {@link \pocketmine\Level} object is no longer usable because it is unloaded while the AsyncTask is
	 * executing, or even a plugin might be unloaded). Since PocketMine keeps a strong reference, the objects are still
	 * valid, but the implementation is responsible for checking whether these objects are still usable.
	 *
	 * WARNING: THIS METHOD SHOULD ONLY BE CALLED FROM THE MAIN THREAD!
	 *
	 * @param mixed $complexData the data to store
	 *
	 * @throws \BadMethodCallException if called from any thread except the main thread
	 */
	protected function storeLocal($complexData){
		if($this->worker !== null and $this->worker === \Thread::getCurrentThread()){
			throw new \BadMethodCallException("Objects can only be stored from the parent thread");
		}

		if(self::$localObjectStorage === null){
			self::$localObjectStorage = new \SplObjectStorage(); //lazy init
		}

		if(isset(self::$localObjectStorage[$this])){
			throw new \InvalidStateException("Already storing complex data for this async task");
		}
		self::$localObjectStorage[$this] = $complexData;
	}

	/**
	 * Returns and removes mixed data in thread-local storage on the parent thread. Call this method from
	 * {@link AsyncTask#onCompletion} to fetch the data stored in the object store, if any.
	 *
	 * If no data was stored in the local store, or if the data was already retrieved by a previous call to fetchLocal,
	 * do NOT call this method, or an exception will be thrown.
	 *
	 * Do not call this method from {@link AsyncTask#onProgressUpdate}, because this method deletes stored data, which
	 * means that you will not be able to retrieve it again afterwards. Use {@link AsyncTask#peekLocal} instead to
	 * retrieve stored data without removing it from the store.
	 *
	 * WARNING: THIS METHOD SHOULD ONLY BE CALLED FROM THE MAIN THREAD!
	 *
	 * @return mixed
	 *
	 * @throws \RuntimeException if no data were stored by this AsyncTask instance.
	 * @throws \BadMethodCallException if called from any thread except the main thread
	 */
	protected function fetchLocal(){
		try{
			return $this->peekLocal();
		}finally{
			if(self::$localObjectStorage !== null){
				unset(self::$localObjectStorage[$this]);
			}
		}
	}

	/**
	 * Returns mixed data in thread-local storage on the parent thread **without clearing** it. Call this method from
	 * {@link AsyncTask#onProgressUpdate} to fetch the data stored if you need to be able to access the data later on,
	 * such as in another progress update.
	 *
	 * Use {@link AsyncTask#fetchLocal} instead from {@link AsyncTask#onCompletion}, because this method does not delete
	 * the data, and not clearing the data will result in a warning for memory leak after {@link AsyncTask#onCompletion}
	 * finished executing.
	 *
	 * WARNING: THIS METHOD SHOULD ONLY BE CALLED FROM THE MAIN THREAD!
	 *
	 * @return mixed
	 *
	 * @throws \RuntimeException if no data were stored by this AsyncTask instance
	 * @throws \BadMethodCallException if called from any thread except the main thread
	 */
	protected function peekLocal(){
		if($this->worker !== null and $this->worker === \Thread::getCurrentThread()){
			throw new \BadMethodCallException("Objects can only be retrieved from the parent thread");
		}

		if(self::$localObjectStorage === null or !isset(self::$localObjectStorage[$this])){
			throw new \InvalidStateException("No complex data stored for this async task");
		}

		return self::$localObjectStorage[$this];
	}

	/**
	 * @internal Called by the AsyncPool to destroy any leftover stored objects that this task failed to retrieve.
	 * @return bool
	 */
	public function removeDanglingStoredObjects() : bool{
		if(self::$localObjectStorage !== null and isset(self::$localObjectStorage[$this])){
			unset(self::$localObjectStorage[$this]);
			return true;
		}

		return false;
	}

	public function cleanObject(){
		foreach($this as $p => $v){
			if(!($v instanceof \Threaded) and !in_array($p, ["isFinished", "isGarbage", "cancelRun"])){
				$this->{$p} = null;
			}
		}

		$this->setGarbage();
	}
}