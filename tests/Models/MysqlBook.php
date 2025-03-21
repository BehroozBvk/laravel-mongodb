<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Eloquent\HybridRelations;

use function assert;

class MysqlBook extends EloquentModel
{
    use HybridRelations;

    protected $connection       = 'mysql';
    protected $table            = 'books';
    protected static $unguarded = true;
    protected $primaryKey       = 'title';

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Check if we need to run the schema.
     */
    public static function executeSchema(): void
    {
        $schema = Schema::connection('mysql');
        assert($schema instanceof MySqlBuilder);

        if ($schema->hasTable('books')) {
            return;
        }

        Schema::connection('mysql')->create('books', function (Blueprint $table) {
            $table->string('title');
            $table->string('author_id')->nullable();
            $table->integer('mysql_user_id')->unsigned()->nullable();
            $table->timestamps();
        });
    }
}
