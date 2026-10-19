<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;

trait LocksWithApprovalDocument
{
    protected static function bootLocksWithApprovalDocument(): void
    {
        $guard = function (Model $line): void {
            $parents = [$line->approvalDocument()];
            if ($line->exists) {
                $parents[] = $line->fresh()?->approvalDocument();
            }
            foreach ($parents as $parent) {
                if ($parent && method_exists($parent, 'assertApprovalDocumentEditable')) {
                    $parent->assertApprovalDocumentEditable();
                }
            }
        };
        static::saving($guard);
        static::deleting($guard);
        static::restoring($guard);
    }
}
