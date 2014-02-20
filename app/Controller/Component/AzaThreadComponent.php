<?php
/**
 * Component for working with AzaThread Thread & ThreadPool classes.
 * AzaThread has to be in the vendors directory.
 */
use Aza\Components\CliBase\Base;
use Aza\Components\LibEvent\EventBase;
use Aza\Components\Thread\Exceptions\Exception;
use Aza\Components\Thread\SimpleThread;
use Aza\Components\Thread\Thread;
use Aza\Components\Thread\ThreadPool;

App::uses('Component', 'Controller');
class AzaThreadComponent extends Component {

	public $maxParallelThreads 	= 1; // How many thread can be processd in parallel

	public $pool 				= null;

	public $poolName 			= null;

	public $threadClassName 	= "AzaThread";

	public $processName 		= null; // Customize process name

	public $debug 				= false;

	public $JobQueue			= null;

/**
 * Called automatically after controller beforeFilter
 * Stores refernece to controller object
 * Merges Settings.history array in $config with default settings
 *
 * @param object $controller
 */
	public function startup(Controller $controller) {
		$this->Controller 		= $controller;
		AzaThread::$useForks 	= true;
		
		// I use the JobQueue Model to store the jobs. You can change this to any model which stores your jobs.
		$this->JobQueue			= ClassRegistry::init('JobQueue');
	}

/**
 * Create a thread and add it to the DB, later we get the job from DB, create the thread and register it in the pool.
 * @param string $threadTaskFunc (serialised closure)
 * @param array $threadTaskFuncParams (closure's parameters)
 */
	public function addThread ($jobType, $userId, $excutionTime, $threadTaskFunc = null, array $threadTaskFuncParams = null) {
		if(!empty($threadTaskFunc) && !empty($jobType) && !empty($userId) && !empty($excutionTime)){
			
			// This is my JobQueue model structure
			$job = array(
				'user_id'			=> $userId,
				'type'				=> $jobType,
				'function' 			=> serialize($threadTaskFunc),
				'function_params' 	=> serialize($threadTaskFuncParams),
				'status'			=> 'PENDING',
				'excution_time'		=> $excutionTime,
				'created'			=> date('Y-m-d H:i:s')
			);
			
			return $this->JobQueue->saveJob($job);
		}
		return false;
	}

/**
 * @return mixed
 */
	public function run ($jobType, $userId, $excutionTime, $jobStartTime){
		
		$totalThreadAmount = $this->JobQueue->countPendingJobs($jobType, $userId, $excutionTime, $jobStartTime);

		if($totalThreadAmount > 0){

			$totalLeft 	= $totalThreadAmount; // Total jobs left

			do {
				// Create thread pool
				$this->pool = new AzaThreadPool($this->threadClassName, 0, $this->processName, $this->poolName, $this->debug);

				// Start process thread pool
				$num  = $totalLeft > $this->maxParallelThreads ? $this->maxParallelThreads : $totalLeft; // Number of jobs in pool of this round
				$left = $num; // Remaining number of jobs of this round

				// Manually register threads
				if($num > 0){
					for($i = 0; $i < $num; $i++){
						$registerThread = $this->JobQueue->getJob($jobType, $userId, $excutionTime, $jobStartTime); // Fetch previous added job
						if(!empty($registerThread)){
							// Register the job in pool
							$thread = new AzaThread($this->processName, $this->pool, $this->debug, array(), $registerThread['id'], $registerThread['function'], $registerThread['function_params']);
							$totalLeft--;
						}
					}
				}

				do{

					try{
						// If we still have jobs to perform
						// And the pool has waiting threads
						while ($left > 0 && $this->pool->hasWaiting()) {
							// You get thread id after start
							$threadId = $this->pool->run();
							$left--;
						}

						if ($results = $this->pool->wait($failed)) {
							foreach ($results as $threadId => $result) {
								// Successfully completed job
								// Result can be identified
								// with thread id ($threadId)

								// Update job status
								if(isset($result['jobId']) && !empty($result['jobId'])){
									$job = $this->JobQueue->browseBy ( $this->JobQueue->primaryKey, $result['jobId'], false);
									if($job){
										$job['JobQueue']['status'] 		= 'DONE';
										$job['JobQueue']['finished'] 	= date ( 'Y-m-d H:i:s', time () );
										$this->JobQueue->updateJob($job['JobQueue']['id'], $job);
									}
								}

								$num--;
							}
						}

						if ($failed) {
							// Error handling.
							// The work is completed unsuccessfully
							// if the child process has died at run time or
							// work timeout exceeded.
							foreach ($failed as $threadId => $err) {
								list($errorCode, $errorMessage) = $err;
								$left++;
							}
						}

					}catch(Exception $e){
						// Sometimes the main event loop may be corrupted, and in this case the main loop needs to be restarted.
						// When this happens, we make sure that we don't lose any jobs and then restart the mail event by cleaning up the pool and start everything all over again
						// TODO improve this process, or if can fix the issue, that will be perfect
						$undoThreadAmount 		= $this->JobQueue->countUndoJobs($jobType, $userId, $excutionTime, $jobStartTime);
						$processedThreadAmount 	= $this->JobQueue->countProcessedJobs($jobType, $userId, $excutionTime, $jobStartTime);

						if($undoThreadAmount != $totalLeft){
							$totalLeft = $undoThreadAmount;
						}

						if(($undoThreadAmount + $processedThreadAmount) >= $totalThreadAmount){
							if($this->pool->getThreadsCount() > 0){
								foreach($this->pool->getThreads() as $thread){
									$thread->cleanup();
								}
							}
							break;
						}
					}

				}while ($num > 0);

				try{
					// Terminating all child processes. Cleanup of resources used by the pool.
					$this->pool->cleanup();
				}catch(Exception $e){
					// Force clean threads here and let the next pool clean up to clean the pool again
					foreach($this->pool->getThreads() as $thread){
						$thread->cleanup();
					}
				}

				// After work it's strongly recommended to clean
				// resources obviously to avoid leaks
				$this->pool->detach();

				// Unset the pool and it will be re-created in the next loop
				$this->pool = null;

			}while($totalLeft > 0);

			return true;

		}else{

			// Queue is empty
			return false;
		}
	}

/**
 * Release resources
 */
	public function reset(){

	}

/**
 * Called after Controller::render() and before the output is printed to the browser.
 * @see Component::shutdown()
 */
	public function shutdown(Controller $controller) {

	}
}

class AzaThread extends Thread {

	public $jobId 					= null;

	public $threadTaskFunc 			= null;

	public $threadTaskFuncParams 	= null;

/**
 * Override construct method
 * @param string $pName
 * @param string $pool
 * @param string $debug
 * @param array $options
 * @param string $threadTaskFunc
 * @param array $threadTaskFuncParams
 * @param string $jobId
 */
	public function __construct($pName = null, $pool = null, $debug = false, array $options = null, $jobId = null, $threadTaskFunc = null, $threadTaskFuncParams = null){
		parent::__construct($pName, $pool, $debug, $options);

		$this->jobId				= $jobId;
		$this->threadTaskFunc 		= unserialize($threadTaskFunc);
		$this->threadTaskFuncParams = unserialize($threadTaskFuncParams);
	}

/**
 * Don't use params to pass arguments to process. Use class properties.
 */
	public function process() {
		$jobId					= $this->getParam(0);
		$threadTaskFunc 		= $this->getParam(1);
		$threadTaskFuncParams 	= $this->getParam(2);

		if(empty($jobId) || empty($threadTaskFunc) || !is_array($threadTaskFuncParams)){
			return null;
		}

		call_user_func_array($threadTaskFunc, $threadTaskFuncParams);

		return array(
			'jobId'    => $jobId
		);
	}
}

class AzaThreadPool extends ThreadPool {

/**
 * Maximum threads number in pool
 * Override this is because if max thread value is less than 1, no thread will be auto-created during thread pool construction period,
 * and after the creation of thread pool, we can manually registered our own pre-created threads.
 */
	protected $maxThreads = 0;

/**
 * Starts job in one of the idle threads
 * @see \Aza\Components\Thread\ThreadPool::run()
 */
	public function run(){
		if ($waiting = $this->waitingForJob) {
			$threadId = reset($waiting);
			$thread   = $this->threads[$threadId];

			// @codeCoverageIgnoreStart
			($debug = $this->debug) && $this->debug(
					"Starting job in thread #{$threadId}..."
			);
			// @codeCoverageIgnoreEnd

			// Use strict call for speedup
			// if number of arguments is not too big
			$args  = func_get_args();
			$count = count($args);
			if (0 === $count) {
				if(isset($thread->threadTaskFunc) && isset($thread->threadTaskFunc) && isset($thread->jobId)){
					$thread->run($thread->jobId, $thread->threadTaskFunc, $thread->threadTaskFuncParams);
				}else{
					$thread->run();
				}
			} else if (1 === $count) {
				$thread->run($args[0]);
			} else if (2 === $count) {
				$thread->run($args[0], $args[1]);
			} else if (3 === $count) {
				$thread->run($args[0], $args[1], $args[2]);
			} else {
				call_user_func_array(array($thread, 'run'), $args);
			}

			// @codeCoverageIgnoreStart
			$debug && $this->debug(
					"Thread #$threadId started"
			);
			// @codeCoverageIgnoreEnd

			return $threadId;
		}

		// Strict approach
		throw new Exception('No threads waiting for the job');
	}
}