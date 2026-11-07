<?php

namespace App\Enums;

enum FieldPlanningMode: string
{
    case Legacy = 'legacy';
    case DirectDcr = 'direct_dcr';
    case Flexible = 'flexible';
    case FormalMtp = 'formal_mtp';
}
