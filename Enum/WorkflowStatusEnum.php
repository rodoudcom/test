<?php

namespace App\WorkflowRodoud\Enum;

enum WorkflowStatusEnum: string
{

    case RUNNING = 'running';
    case SUCCESS = 'success';
    case FAIL = 'fail';


    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function getDefault(): self
    {
        return self::RUNNING;
    }
}
