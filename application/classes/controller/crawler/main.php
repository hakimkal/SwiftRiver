<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Crawler Controller
 *
 * PHP version 5
 * LICENSE: This source file is subject to GPLv3 license 
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/gpl.html
 * @author     Ushahidi Team <team@ushahidi.com> 
 * @package    SwiftRiver - http://github.com/ushahidi/Swiftriver_v2
 * @subpackage Controllers
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License v3 (GPLv3) 
 */

// Check if the PCNTL functions exist - Sorry Windows peeps!
if ( ! function_exists('pcntl_fork'))
{
	Kohana::$log->add(Log::ERROR, 'PCNTL functions are not available in this PHP installation');
	exit;
}

declare(ticks = 1);

class Controller_Crawler_Main extends Controller {
	
	/**
	 * Process ID of the current process
	 * @var int
	 */
	private $current_pid;
	
	/**
	 * List of currently forked processes
	 * @var array
	 */
	private $current_procs = array();
	
	/**
	 * Processes that have exited before the parent
	 * @var array
	 */
	private $signal_queue = array();
	
	
	public function before()
	{
		parent::before();
		
		// Get the current process id
		$this->current_pid = getmypid();
	}
	
	
	/**
	 * Run the channel worker
	 */
	public function action_index()
	{	
		
		// Get all the available services
		$services = Swiftriver_Plugins::channels();
	
		// Check if any services have been found
		if (empty($services))
		{
			Kohana::$log->add(Log::ERROR, 'No channel services found');
			exit;
		}
		
		// Register the signal handler
		pcntl_signal(SIGCHLD, array($this, 'handle_child_signal'));
		
		// Create a worker for each channel
		$this->launch_worker('on_complete_task', array('Swiftriver_Channel_Worker', 'on_complete_task'));
		
		foreach ($services as $key => $value)
		{
			$this->launch_worker($key);
		}
				
		// Wait for the child processes to finish
		while (count($this->current_procs))
		{
			// Prevents PHP from munching the CPU
			sleep(10);
		}
		
	}
	
	/**
	 * Launches a worker for the specified job
	 *
	 * @param string  $job_name Name of the function to register with the Gearman server
	 * @param array   $callback The callback to be called when a job for the $job_name is submitted
	 */
	protected function launch_worker($job_name, $callback = array())
	{
		// Fork process for the specified job
		$process_id = pcntl_fork();
	
		if ($process_id == -1)
		{
			// Error
			Kohana::$log->add(Log::ERROR, 'Could not fork worker process for the :job job', 
				array(':channel' => $job_name));
		
			exit(1);
		}
		elseif ($process_id)
		{	
			//This is the parent process. $process_id is the child pid
			
			
			$this->current_procs[$process_id] = $job_name;
				
			// Check if the signal for the current child process has been caught
			if (isset($this->signal_queue[$process_id]))
			{
				// Handle the signal
				$this->handle_child_signal(SIGCHLD, $process_id, $this->signal_queue[$process_id]);
			
				// Remove process from the signal queue
				unset ($this->signal_queue[$process_id]);
			}
		}
		else
		{

			//This is the child process. Register the worker and work.

			// Log
			Kohana::$log->add(Log::DEBUG, 'Forked process :pid for :job', 
				array(':pid' => getmypid(), ':job' => strtoupper($job_name)));

			// Create the worker object
			$worker = new GearmanWorker();

			// Add the default server
			$worker->addServer();
			
			if (empty($callback))
			{
				// Create instance for the channel worker. If not found, the
				// framework will throw an exception
				$instance = Swiftriver_Channel_Worker::factory($job_name);
			
				// Register the callback function
				$worker->addFunction($job_name, array($instance, 'channel_worker'));
			}
			else
			{
				$worker->addFunction($job_name, $callback);
			}
			
			// Listen for job request
			while ($worker->work())
			{
				// Check for errors in the worker
				if ($worker->returnCode() != GEARMAN_SUCCESS)
				{
					Kohana::$log->add(Log::DEBUG, ':job worker returned an error: :error', 
						array(':job' => $job_name, ':error' => $worker->error()));
				}
			}
			
		}		
	}
	
	
	/**
	 * Signal handler for the child process
	 *
	 * @param int $signo Signal number
	 * @param int $pid Process ID of the child process
	 * @param int $status Status of the process
	 */
	public function handle_child_signal($signo, $pid = NULL, $status = NULL)
	{
		// If no pid is provided, we're getting the signal from the system. Let's find out which
		// process ended
		if ( ! $pid)
		{
			$pid = pcntl_waitpid(-1, $status, WNOHANG);
		}
		
		// Get all the children that have exited
		while ($pid > 0)
		{
			if ($pid AND isset($this->current_procs[$pid]))
			{
				// Get the exit status of the terminated child process
				$exit_code = pcntl_wexitstatus($status);
				
				// Check for clean exit
				if ($exit_code != 0)
				{
					// Log the error
					Kohana::$log->add(Log::ERROR, 'Process :pid exited with status :code', 
						array(':pid' => $pid, ':code' => $exit_code));
				}
				
				// Remove the process from the list of current processes
				unset ($this->current_procs[$pid]);
			}
			elseif ($pid)
			{
				// The child process has finished before parent process. Add it to the signal queue
				// so that the parent can deal with it
				$this->signal_queue[$pid] = $status;
			}
			
			// Wait for the child to exit and return immediately if no child has exited
			$pid = pcntl_waitpid(-1, $status, WNOHANG);
		}
		
		return TRUE;
	}
	
}
?>
