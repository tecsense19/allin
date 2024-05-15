<?php

namespace App\Http\Traits;

trait CreatedUpdatedBy
{
    public static function bootCreatedUpdatedBy()
    {
        // updating created_by and updated_by when model is created
        static::creating(function ($model) {
            if (!$model->isDirty('created_by')) {
                $model->created_by = (!empty(auth()->user()->id))? auth()->user()->id : NULL;
            }
        });

        // updating updated_by when model is updated
        static::updating(function ($model) {
            if (!$model->isDirty('updated_by')) {
                $model->updated_by = (!empty(auth()->user()->id))? auth()->user()->id : NULL;
            }
        });
        static::deleting(function ($model) {
            $model->deleted_by = (!empty(auth()->user()->id))? auth()->user()->id : NULL;
            $model->save();
        });
    }
}