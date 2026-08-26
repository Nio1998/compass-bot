<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una slide (PDF) del prof. Palomba caricata dal pannello admin.
 * Tiene traccia dello stato di ingestione nel vector store RAG.
 */
class SlideDocument extends Model
{
    protected $fillable = [
        'original_name',
        'path',
        'status',
        'chunk_count',
        'error',
        'ingested_at',
    ];

    protected $casts = [
        'ingested_at' => 'datetime',
    ];

    public function isIngested(): bool
    {
        return $this->status === 'ingested';
    }
}
