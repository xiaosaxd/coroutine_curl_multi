<?php
    require_once 'Task.php';
    require_once 'Curl.php';

    class BaseScheduler
    {
        protected $maxTaskId = 0;
        /**
         * @var SplQueue
         */
        protected $taskQueue;

        public function __construct(){
            $this->taskQueue = new SplQueue;
        }

        public function newTask(Generator $gen){
            $task = new Task(++$this->maxTaskId, $gen);
            $this->schedule($task);
            return $this->maxTaskId;
        }

        public function schedule(Task $task){
            $this->taskQueue->enqueue($task);
        }

        public function run(){
            while(!$this->taskQueue->isEmpty()){
                $task = $this->taskQueue->dequeue();
                $ret = $task->run();

                if($ret instanceof SysCall){
                    $ret($task, $this);
                    continue;
                }

                if(!$task->isFinished()){
                    $this->schedule($task);
                }
            }
        }
    }

    trait poll
    {
        protected $readPoll = [];
        protected $writePoll = [];

        public function addToReadPoll($socket, Task $task){
            if(isset($this->readPoll[(int)$socket])){
                $this->readPoll[(int)$socket][1][] = $task;
            }else{
                $this->readPoll[(int)$socket] = [$socket, [$task]];
            }
        }

        public function addToWritePoll($socket, Task $task){
            if(isset($this->writePoll[(int)$socket])){
                $this->writePoll[(int)$socket][1][] = $task;
            }else{
                $this->writePoll[(int)$socket] = [$socket, [$task]];
            }
        }
    }

    class Scheduler extends BaseScheduler
    {
        use poll;

        public function scheduleReadySocks($timeout){
            $rSocks = [];
            if(!empty($this->readPoll)){
                foreach($this->readPoll as list($socket)){
                    $rSocks[] = $socket;
                }
            }
            $wSocks = [];
            if(!empty($this->writePoll)){
                foreach($this->writePoll as list($socket)){
                    $wSocks[] = $socket;
                }
            }
            $eSocks = [];
            if(!empty($rSocks) || !empty($wSocks)){
                if(!stream_select($rSocks, $wSocks, $eSocks, $timeout)){
                    return;
                }
                if(!empty($rSocks)){
                    foreach($rSocks as $socket){
                        list(, $tasks) = $this->readPoll[(int)$socket];
                        unset($this->readPoll[(int)$socket]);
                        foreach($tasks as $task){
                            $this->schedule($task);
                        }
                    }
                }
                if(!empty($wSocks)){
                    foreach($wSocks as $socket){
                        list(, $tasks) = $this->writePoll[(int)$socket];
                        unset($this->writePoll[(int)$socket]);
                        foreach($tasks as $task){
                            $this->schedule($task);
                        }
                    }
                }
            }
        }

        public function daemon(){
            while(true){
                $this->scheduleReadySocks(empty($this->taskQueue) ? null : 0);
                yield;
            }
        }

        public function run(){
            $ret = [];
            $count = count($this->taskQueue);

            $this->newTask($this->daemon());
            while(!$this->taskQueue->isEmpty()){
                $task = $this->taskQueue->dequeue();
                $rs = $task->run();

                if($rs instanceof SysCall){
                    $rs($task, $this);
                    continue;
                }

                if($rs instanceof CurlException || $rs instanceof CurlResult){
                    $ret = array_merge($ret, $rs->getValue());
                    if(-- $count){
                        continue;
                    }else{
                        break;
                    }
                }

                if(!$task->isFinished()){
                    $this->schedule($task);
                }
            }

            return $ret;
        }
    }