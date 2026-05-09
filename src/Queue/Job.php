<?php

namespace Anon\Core\Queue;

interface Job
{
    /**
     * 执行任务
     */
    public function handle(): void;
}
