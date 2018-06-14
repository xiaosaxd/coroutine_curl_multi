<?php

    class SysCall
    {
        private $cb;

        public function __construct(Callable $cb){
            $this->cb = $cb;
        }

        public function __invoke(Task $task, Scheduler $scheduler){
            call_user_func_array($this->cb, [$task, $scheduler]);
        }
    }
