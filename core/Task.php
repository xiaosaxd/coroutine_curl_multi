<?php

    class Task
    {
        protected $taskId;
        /**
         * @var Generator
         */
        protected $gen;
        protected $firstYield = true;
        protected $sendValue = null;

        public function __construct($taskId, Generator $gen){
            $this->taskId = $taskId;
            $this->gen = $gen;
        }

        public function setSendValue($val = null){
            $this->sendValue = $val;
        }

        public function isFinished(){
            return !$this->gen->valid();
        }

        public function run(){
            if($this->firstYield){
                $this->firstYield = false;
                return $this->gen->current();
            }else{
                $this->setSendValue();
                return $this->gen->send($this->sendValue);
            }
        }
    }

