<?php

namespace GeoMin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

/**
 * Spatial Analysis Model
 * 
 * Eloquent model for storing GeoMin analysis results.
 * Tracks analysis type, status, parameters, and output paths.
 * 
 * @property int $id
 * @property string $type
 * @property string $status
 * @property array $parameters
 * @property string|null $file_path
 * @property string|null $result_path
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @author Kazashim Kuzasuwat
 */
class SpatialAnalysis extends Model
{
    use HasFactory;

    /**
     * Analysis types.
     */
    public const TYPE_ANOMALY_DETECTION = 'anomaly_detection';
    public const TYPE_CLOUD_MASKING = 'cloud_masking';
    public const TYPE_MINERAL_MAPPING = 'mineral_mapping';
    public const TYPE_SPECTRAL_INDEX = 'spectral_index';
    public const TYPE_CROSTA_PCA = 'crosta_pca';
    public const TYPE_SPECTRAL_UNMIXING = 'spectral_unmixing';
    public const TYPE_SAM = 'sam';

    /**
     * Status values.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'type',
        'status',
        'parameters',
        'file_path',
        'result_path',
        'metadata',
        'user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'parameters' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    }

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [];

    /**
     * Get the user that owns this analysis.
     */
    public function user()
    {
        return $this->belongsTo(config('auth.providers.users.model') ?: 'App\Models\User');
    }

    /**
     * Check if analysis is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if analysis is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if analysis is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if analysis failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark as processing.
     */
    public function markAsProcessing(): self
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
        return $this;
    }

    /**
     * Mark as completed.
     */
    public function markAsCompleted(array $result = []): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'metadata' => array_merge($this->metadata ?? [], $result),
        ]);
        return $this;
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(string $error): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'metadata' => array_merge($this->metadata ?? [], ['error' => $error]),
        ]);
        return $this;
    }

    /**
     * Get the input file.
     */
    public function getInputFile(): ?string
    {
        return $this->file_path;
    }

    /**
     * Get the result file.
     */
    public function getResultFile(): ?string
    {
        return $this->result_path;
    }

    /**
     * Get result from storage.
     */
    public function getResult(): ?array
    {
        if (!$this->result_path) {
            return null;
        }

        if (Storage::exists($this->result_path)) {
            return json_decode(Storage::get($this->result_path), true);
        }

        return null;
    }

    /**
     * Save result to storage.
     */
    public function saveResult(array $result): string
    {
        $filename = "geomin/results/{$this->id}/result.json";
        Storage::put($filename, json_encode($result));
        
        $this->update(['result_path' => $filename]);
        
        return $filename;
    }

    /**
     * Get parameters as array.
     */
    public function getParameters(): array
    {
        return $this->parameters ?? [];
    }

    /**
     * Get a specific parameter.
     */
    public function getParameter(string $key, $default = null)
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Get analysis type display name.
     */
    public function getTypeName(): string
    {
        return match ($this->type) {
            self::TYPE_ANOMALY_DETECTION => 'Anomaly Detection',
            self::TYPE_CLOUD_MASKING => 'Cloud Masking',
            self::TYPE_MINERAL_MAPPING => 'Mineral Mapping',
            self::TYPE_SPECTRAL_INDEX => 'Spectral Index',
            self::TYPE_CROSTA_PCA => 'Crosta PCA',
            self::TYPE_SPECTRAL_UNMIXING => 'Spectral Unmixing',
            self::TYPE_SAM => 'Spectral Angle Mapper',
            default => 'Unknown',
        };
    }

    /**
     * Get status display name.
     */
    public function getStatusName(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            default => 'Unknown',
        };
    }

    /**
     * Get status color for UI.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'yellow',
            self::STATUS_PROCESSING => 'blue',
            self::STATUS_COMPLETED => 'green',
            self::STATUS_FAILED => 'red',
            default => 'gray',
        };
    }

    /**
     * Scope: Pending analyses.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope: Processing analyses.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope: Completed analyses.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope: By type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: By user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Recent analyses.
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
