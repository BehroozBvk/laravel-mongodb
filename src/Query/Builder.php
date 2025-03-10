<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Query;

use ArgumentCountError;
use BadMethodCallException;
use Carbon\CarbonPeriod;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use LogicException;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Cursor;
use RuntimeException;
use Stringable;

use function array_fill_keys;
use function array_is_list;
use function array_key_exists;
use function array_merge;
use function array_merge_recursive;
use function array_values;
use function array_walk_recursive;
use function assert;
use function blank;
use function call_user_func;
use function call_user_func_array;
use function count;
use function ctype_xdigit;
use function end;
use function explode;
use function func_get_args;
use function func_num_args;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function md5;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function serialize;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

class Builder extends BaseBuilder
{
    private const REGEX_DELIMITERS = ['/', '#', '~'];

    /**
     * The database collection.
     *
     * @var \MongoDB\Collection
     */
    protected $collection;

    /**
     * The column projections.
     *
     * @var array
     */
    public $projections;

    /**
     * The cursor timeout value.
     *
     * @var int
     */
    public $timeout;

    /**
     * The cursor hint value.
     *
     * @var int
     */
    public $hint;

    /**
     * Custom options to add to the query.
     *
     * @var array
     */
    public $options = [];

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    public $operators = [
        '=',
        '<',
        '>',
        '<=',
        '>=',
        '<>',
        '!=',
        'like',
        'not like',
        'between',
        'ilike',
        '&',
        '|',
        '^',
        '<<',
        '>>',
        'rlike',
        'regexp',
        'not regexp',
        'exists',
        'type',
        'mod',
        'where',
        'all',
        'size',
        'regex',
        'not regex',
        'text',
        'slice',
        'elemmatch',
        'geowithin',
        'geointersects',
        'near',
        'nearsphere',
        'geometry',
        'maxdistance',
        'center',
        'centersphere',
        'box',
        'polygon',
        'uniquedocs',
    ];

    /**
     * Operator conversion.
     *
     * @var array
     */
    protected $conversion = [
        '=' => 'eq',
        '!=' => 'ne',
        '<>' => 'ne',
        '<' => 'lt',
        '<=' => 'lte',
        '>' => 'gt',
        '>=' => 'gte',
        'regexp' => 'regex',
        'not regexp' => 'not regex',
        'ilike' => 'like',
        'elemmatch' => 'elemMatch',
        'geointersects' => 'geoIntersects',
        'geowithin' => 'geoWithin',
        'nearsphere' => 'nearSphere',
        'maxdistance' => 'maxDistance',
        'centersphere' => 'centerSphere',
        'uniquedocs' => 'uniqueDocs',
    ];

    /**
     * Set the projections.
     *
     * @param  array $columns
     *
     * @return $this
     */
    public function project($columns)
    {
        $this->projections = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * Set the cursor timeout in seconds.
     *
     * @param  int $seconds
     *
     * @return $this
     */
    public function timeout($seconds)
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the cursor hint.
     *
     * @param  mixed $index
     *
     * @return $this
     */
    public function hint($index)
    {
        $this->hint = $index;

        return $this;
    }

    /** @inheritdoc */
    public function find($id, $columns = [])
    {
        return $this->where('_id', '=', $this->convertKey($id))->first($columns);
    }

    /** @inheritdoc */
    public function value($column)
    {
        $result = (array) $this->first([$column]);

        return Arr::get($result, $column);
    }

    /** @inheritdoc */
    public function get($columns = [])
    {
        return $this->getFresh($columns);
    }

    /** @inheritdoc */
    public function cursor($columns = [])
    {
        $result = $this->getFresh($columns, true);
        if ($result instanceof LazyCollection) {
            return $result;
        }

        throw new RuntimeException('Query not compatible with cursor');
    }

    /**
     * Return the MongoDB query to be run in the form of an element array like ['method' => [arguments]].
     *
     * Example: ['find' => [['name' => 'John Doe'], ['projection' => ['birthday' => 1]]]]
     *
     * @return array<string, mixed[]>
     */
    public function toMql(): array
    {
        $columns = $this->columns ?? [];

        // Drop all columns if * is present, MongoDB does not work this way.
        if (in_array('*', $columns)) {
            $columns = [];
        }

        $wheres = $this->compileWheres();

        // Use MongoDB's aggregation framework when using grouping or aggregation functions.
        if ($this->groups || $this->aggregate) {
            $group   = [];
            $unwinds = [];

            // Add grouping columns to the $group part of the aggregation pipeline.
            if ($this->groups) {
                foreach ($this->groups as $column) {
                    $group['_id'][$column] = '$' . $column;

                    // When grouping, also add the $last operator to each grouped field,
                    // this mimics MySQL's behaviour a bit.
                    $group[$column] = ['$last' => '$' . $column];
                }

                // Do the same for other columns that are selected.
                foreach ($columns as $column) {
                    $key = str_replace('.', '_', $column);

                    $group[$key] = ['$last' => '$' . $column];
                }
            }

            // Add aggregation functions to the $group part of the aggregation pipeline,
            // these may override previous aggregations.
            if ($this->aggregate) {
                $function = $this->aggregate['function'];

                foreach ($this->aggregate['columns'] as $column) {
                    // Add unwind if a subdocument array should be aggregated
                    // column: subarray.price => {$unwind: '$subarray'}
                    $splitColumns = explode('.*.', $column);
                    if (count($splitColumns) === 2) {
                        $unwinds[] = $splitColumns[0];
                        $column    = implode('.', $splitColumns);
                    }

                    $aggregations = blank($this->aggregate['columns']) ? [] : $this->aggregate['columns'];

                    if (in_array('*', $aggregations) && $function === 'count') {
                        $options = $this->inheritConnectionOptions();

                        return ['countDocuments' => [$wheres, $options]];
                    }

                    if ($function === 'count') {
                        // Translate count into sum.
                        $group['aggregate'] = ['$sum' => 1];
                    } else {
                        $group['aggregate'] = ['$' . $function => '$' . $column];
                    }
                }
            }

            // The _id field is mandatory when using grouping.
            if ($group && empty($group['_id'])) {
                $group['_id'] = null;
            }

            // Build the aggregation pipeline.
            $pipeline = [];
            if ($wheres) {
                $pipeline[] = ['$match' => $wheres];
            }

            // apply unwinds for subdocument array aggregation
            foreach ($unwinds as $unwind) {
                $pipeline[] = ['$unwind' => '$' . $unwind];
            }

            if ($group) {
                $pipeline[] = ['$group' => $group];
            }

            // Apply order and limit
            if ($this->orders) {
                $pipeline[] = ['$sort' => $this->orders];
            }

            if ($this->offset) {
                $pipeline[] = ['$skip' => $this->offset];
            }

            if ($this->limit) {
                $pipeline[] = ['$limit' => $this->limit];
            }

            if ($this->projections) {
                $pipeline[] = ['$project' => $this->projections];
            }

            $options = [
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ];

            // Add custom query options
            if (count($this->options)) {
                $options = array_merge($options, $this->options);
            }

            $options = $this->inheritConnectionOptions($options);

            return ['aggregate' => [$pipeline, $options]];
        }

        // Distinct query
        if ($this->distinct) {
            // Return distinct results directly
            $column = $columns[0] ?? '_id';

            $options = $this->inheritConnectionOptions();

            return ['distinct' => [$column, $wheres, $options]];
        }

        // Normal query
        // Convert select columns to simple projections.
        $projection = array_fill_keys($columns, true);

        // Add custom projections.
        if ($this->projections) {
            $projection = array_merge($projection, $this->projections);
        }

        $options = [];

        // Apply order, offset, limit and projection
        if ($this->timeout) {
            $options['maxTimeMS'] = $this->timeout * 1000;
        }

        if ($this->orders) {
            $options['sort'] = $this->orders;
        }

        if ($this->offset) {
            $options['skip'] = $this->offset;
        }

        if ($this->limit) {
            $options['limit'] = $this->limit;
        }

        if ($this->hint) {
            $options['hint'] = $this->hint;
        }

        if ($projection) {
            $options['projection'] = $projection;
        }

        // Fix for legacy support, converts the results to arrays instead of objects.
        $options['typeMap'] = ['root' => 'array', 'document' => 'array'];

        // Add custom query options
        if (count($this->options)) {
            $options = array_merge($options, $this->options);
        }

        $options = $this->inheritConnectionOptions($options);

        return ['find' => [$wheres, $options]];
    }

    /**
     * Execute the query as a fresh "select" statement.
     *
     * @param  array $columns
     * @param  bool  $returnLazy
     *
     * @return array|static[]|Collection|LazyCollection
     */
    public function getFresh($columns = [], $returnLazy = false)
    {
        // If no columns have been specified for the select statement, we will set them
        // here to either the passed columns, or the standard default of retrieving
        // all of the columns on the table using the "wildcard" column character.
        if ($this->columns === null) {
            $this->columns = $columns;
        }

        // Drop all columns if * is present, MongoDB does not work this way.
        if (in_array('*', $this->columns)) {
            $this->columns = [];
        }

        $command = $this->toMql();
        assert(count($command) >= 1, 'At least one method call is required to execute a query');

        $result = $this->collection;
        foreach ($command as $method => $arguments) {
            $result = call_user_func_array([$result, $method], $arguments);
        }

        // countDocuments method returns int, wrap it to the format expected by the framework
        if (is_int($result)) {
            $result = [
                [
                    '_id'       => null,
                    'aggregate' => $result,
                ],
            ];
        }

        if ($returnLazy) {
            return LazyCollection::make(function () use ($result) {
                foreach ($result as $item) {
                    yield $item;
                }
            });
        }

        if ($result instanceof Cursor) {
            $result = $result->toArray();
        }

        return new Collection($result);
    }

    /**
     * Generate the unique cache key for the current query.
     *
     * @return string
     */
    public function generateCacheKey()
    {
        $key = [
            'connection' => $this->collection->getDatabaseName(),
            'collection' => $this->collection->getCollectionName(),
            'wheres' => $this->wheres,
            'columns' => $this->columns,
            'groups' => $this->groups,
            'orders' => $this->orders,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'aggregate' => $this->aggregate,
        ];

        return md5(serialize(array_values($key)));
    }

    /** @inheritdoc */
    public function aggregate($function, $columns = [])
    {
        $this->aggregate = [
            'function' => $function,
            'columns' => $columns,
        ];

        $previousColumns = $this->columns;

        // We will also back up the select bindings since the select clause will be
        // removed when performing the aggregate function. Once the query is run
        // we will add the bindings back onto this query so they can get used.
        $previousSelectBindings = $this->bindings['select'];

        $this->bindings['select'] = [];

        $results = $this->get($columns);

        // Once we have executed the query, we will reset the aggregate property so
        // that more select queries can be executed against the database without
        // the aggregate value getting in the way when the grammar builds it.
        $this->aggregate          = null;
        $this->columns            = $previousColumns;
        $this->bindings['select'] = $previousSelectBindings;

        if (isset($results[0])) {
            $result = (array) $results[0];

            return $result['aggregate'];
        }
    }

    /** @inheritdoc */
    public function exists()
    {
        return $this->first() !== null;
    }

    /** @inheritdoc */
    public function distinct($column = false)
    {
        $this->distinct = true;

        if ($column) {
            $this->columns = [$column];
        }

        return $this;
    }

    /**
     * @param int|string|array $direction
     *
     * @inheritdoc
     */
    public function orderBy($column, $direction = 'asc')
    {
        if (is_string($direction)) {
            $direction = match ($direction) {
                'asc', 'ASC' => 1,
                'desc', 'DESC' => -1,
                default => throw new InvalidArgumentException('Order direction must be "asc" or "desc".'),
            };
        }

        $column = (string) $column;
        if ($column === 'natural') {
            $this->orders['$natural'] = $direction;
        } else {
            $this->orders[$column] = $direction;
        }

        return $this;
    }

    /**
     * @param list{mixed, mixed}|CarbonPeriod $values
     *
     * @inheritdoc
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        $type = 'between';

        if ($values instanceof Collection) {
            $values = $values->all();
        }

        if (is_array($values) && (! array_is_list($values) || count($values) !== 2)) {
            throw new InvalidArgumentException('Between $values must be a list with exactly two elements: [min, max]');
        }

        $this->wheres[] = [
            'column'  => $column,
            'type'    => $type,
            'boolean' => $boolean,
            'values'  => $values,
            'not'     => $not,
        ];

        return $this;
    }

    /** @inheritdoc */
    public function insert(array $values)
    {
        // Since every insert gets treated like a batch insert, we will have to detect
        // if the user is inserting a single document or an array of documents.
        $batch = true;

        foreach ($values as $value) {
            // As soon as we find a value that is not an array we assume the user is
            // inserting a single document.
            if (! is_array($value)) {
                $batch = false;
                break;
            }
        }

        if (! $batch) {
            $values = [$values];
        }

        $options = $this->inheritConnectionOptions();

        $result = $this->collection->insertMany($values, $options);

        return $result->isAcknowledged();
    }

    /** @inheritdoc */
    public function insertGetId(array $values, $sequence = null)
    {
        $options = $this->inheritConnectionOptions();

        $result = $this->collection->insertOne($values, $options);

        if (! $result->isAcknowledged()) {
            return null;
        }

        if ($sequence === null || $sequence === '_id') {
            return $result->getInsertedId();
        }

        return $values[$sequence];
    }

    /** @inheritdoc */
    public function update(array $values, array $options = [])
    {
        // Use $set as default operator for field names that are not in an operator
        foreach ($values as $key => $value) {
            if (str_starts_with($key, '$')) {
                continue;
            }

            $values['$set'][$key] = $value;
            unset($values[$key]);
        }

        $options = $this->inheritConnectionOptions($options);

        return $this->performUpdate($values, $options);
    }

    /** @inheritdoc */
    public function increment($column, $amount = 1, array $extra = [], array $options = [])
    {
        $query = ['$inc' => [(string) $column => $amount]];

        if (! empty($extra)) {
            $query['$set'] = $extra;
        }

        // Protect
        $this->where(function ($query) use ($column) {
            $query->where($column, 'exists', false);

            $query->orWhereNotNull($column);
        });

        $options = $this->inheritConnectionOptions($options);

        return $this->performUpdate($query, $options);
    }

    /** @inheritdoc */
    public function decrement($column, $amount = 1, array $extra = [], array $options = [])
    {
        return $this->increment($column, -1 * $amount, $extra, $options);
    }

    /** @inheritdoc */
    public function chunkById($count, callable $callback, $column = '_id', $alias = null)
    {
        return parent::chunkById($count, $callback, $column, $alias);
    }

    /** @inheritdoc */
    public function forPageAfterId($perPage = 15, $lastId = 0, $column = '_id')
    {
        return parent::forPageAfterId($perPage, $lastId, $column);
    }

    /** @inheritdoc */
    public function pluck($column, $key = null)
    {
        $results = $this->get($key === null ? [$column] : [$column, $key]);

        // Convert ObjectID's to strings
        if (((string) $key) === '_id') {
            $results = $results->map(function ($item) {
                $item['_id'] = (string) $item['_id'];

                return $item;
            });
        }

        $p = Arr::pluck($results, $column, $key);

        return new Collection($p);
    }

    /** @inheritdoc */
    public function delete($id = null)
    {
        // If an ID is passed to the method, we will set the where clause to check
        // the ID to allow developers to simply and quickly remove a single row
        // from their database without manually specifying the where clauses.
        if ($id !== null) {
            $this->where('_id', '=', $id);
        }

        $wheres  = $this->compileWheres();
        $options = $this->inheritConnectionOptions();

        if (is_int($this->limit)) {
            if ($this->limit !== 1) {
                throw new LogicException(sprintf('Delete limit can be 1 or null (unlimited). Got %d', $this->limit));
            }

            $result = $this->collection->deleteOne($wheres, $options);
        } else {
            $result = $this->collection->deleteMany($wheres, $options);
        }

        if ($result->isAcknowledged()) {
            return $result->getDeletedCount();
        }

        return 0;
    }

    /** @inheritdoc */
    public function from($collection, $as = null)
    {
        if ($collection) {
            $this->collection = $this->connection->getCollection($collection);
        }

        return parent::from($collection);
    }

    public function truncate(): bool
    {
        $options = $this->inheritConnectionOptions();
        $result  = $this->collection->deleteMany([], $options);

        return $result->isAcknowledged();
    }

    /**
     * Get an array with the values of a given column.
     *
     * @deprecated Use pluck instead.
     *
     * @param  string $column
     * @param  string $key
     *
     * @return Collection
     */
    public function lists($column, $key = null)
    {
        return $this->pluck($column, $key);
    }

    /** @inheritdoc */
    public function raw($value = null)
    {
        // Execute the closure on the mongodb collection
        if ($value instanceof Closure) {
            return call_user_func($value, $this->collection);
        }

        // Create an expression for the given value
        if ($value !== null) {
            return new Expression($value);
        }

        // Quick access to the mongodb collection
        return $this->collection;
    }

    /**
     * Append one or more values to an array.
     *
     * @param  string|array $column
     * @param  mixed        $value
     * @param  bool         $unique
     *
     * @return int
     */
    public function push($column, $value = null, $unique = false)
    {
        // Use the addToSet operator in case we only want unique items.
        $operator = $unique ? '$addToSet' : '$push';

        // Check if we are pushing multiple values.
        $batch = is_array($value) && array_is_list($value);

        if (is_array($column)) {
            if ($value !== null) {
                throw new InvalidArgumentException(sprintf('2nd argument of %s() must be "null" when 1st argument is an array. Got "%s" instead.', __METHOD__, get_debug_type($value)));
            }

            $query = [$operator => $column];
        } elseif ($batch) {
            $query = [$operator => [(string) $column => ['$each' => $value]]];
        } else {
            $query = [$operator => [(string) $column => $value]];
        }

        return $this->performUpdate($query);
    }

    /**
     * Remove one or more values from an array.
     *
     * @param  string|array $column
     * @param  mixed        $value
     *
     * @return int
     */
    public function pull($column, $value = null)
    {
        // Check if we passed an associative array.
        $batch = is_array($value) && array_is_list($value);

        // If we are pulling multiple values, we need to use $pullAll.
        $operator = $batch ? '$pullAll' : '$pull';

        if (is_array($column)) {
            $query = [$operator => $column];
        } else {
            $query = [$operator => [$column => $value]];
        }

        return $this->performUpdate($query);
    }

    /**
     * Remove one or more fields.
     *
     * @param  string|string[] $columns
     *
     * @return int
     */
    public function drop($columns)
    {
        if (! is_array($columns)) {
            $columns = [$columns];
        }

        $fields = [];

        foreach ($columns as $column) {
            $fields[$column] = 1;
        }

        $query = ['$unset' => $fields];

        return $this->performUpdate($query);
    }

    /**
     * @return static
     *
     * @inheritdoc
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar, $this->processor);
    }

    /**
     * Perform an update query.
     *
     * @param  array $query
     *
     * @return int
     */
    protected function performUpdate($query, array $options = [])
    {
        // Update multiple items by default.
        if (! array_key_exists('multiple', $options)) {
            $options['multiple'] = true;
        }

        $options = $this->inheritConnectionOptions($options);

        $wheres = $this->compileWheres();
        $result = $this->collection->updateMany($wheres, $query, $options);
        if ($result->isAcknowledged()) {
            return $result->getModifiedCount() ? $result->getModifiedCount() : $result->getUpsertedCount();
        }

        return 0;
    }

    /**
     * Convert a key to ObjectID if needed.
     *
     * @param  mixed $id
     *
     * @return mixed
     */
    public function convertKey($id)
    {
        if (is_string($id) && strlen($id) === 24 && ctype_xdigit($id)) {
            return new ObjectID($id);
        }

        if (is_string($id) && strlen($id) === 16 && preg_match('~[^\x20-\x7E\t\r\n]~', $id) > 0) {
            return new Binary($id, Binary::TYPE_UUID);
        }

        return $id;
    }

    /** @inheritdoc */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $params = func_get_args();

        // Remove the leading $ from operators.
        if (func_num_args() >= 3) {
            $operator = &$params[1];

            if (is_string($operator) && str_starts_with($operator, '$')) {
                $operator = substr($operator, 1);
            }
        }

        if (func_num_args() === 1 && is_string($column)) {
            throw new ArgumentCountError(sprintf('Too few arguments to function %s("%s"), 1 passed and at least 2 expected when the 1st is a string.', __METHOD__, $column));
        }

        return parent::where(...$params);
    }

    /**
     * Compile the where array.
     *
     * @return array
     */
    protected function compileWheres(): array
    {
        // The wheres to compile.
        $wheres = $this->wheres ?: [];

        // We will add all compiled wheres to this array.
        $compiled = [];

        foreach ($wheres as $i => &$where) {
            // Make sure the operator is in lowercase.
            if (isset($where['operator'])) {
                $where['operator'] = strtolower($where['operator']);

                // Convert aliased operators
                if (isset($this->conversion[$where['operator']])) {
                    $where['operator'] = $this->conversion[$where['operator']];
                }
            }

            // Convert column name to string to use as array key
            if (isset($where['column']) && $where['column'] instanceof Stringable) {
                $where['column'] = (string) $where['column'];
            }

            // Convert id's.
            if (isset($where['column']) && ($where['column'] === '_id' || str_ends_with($where['column'], '._id'))) {
                if (isset($where['values'])) {
                    // Multiple values.
                    foreach ($where['values'] as &$value) {
                        $value = $this->convertKey($value);
                    }
                } elseif (isset($where['value'])) {
                    // Single value.
                    $where['value'] = $this->convertKey($where['value']);
                }
            }

            // Convert DateTime values to UTCDateTime.
            if (isset($where['value'])) {
                if (is_array($where['value'])) {
                    array_walk_recursive($where['value'], function (&$item, $key) {
                        if ($item instanceof DateTimeInterface) {
                            $item = new UTCDateTime($item);
                        }
                    });
                } else {
                    if ($where['value'] instanceof DateTimeInterface) {
                        $where['value'] = new UTCDateTime($where['value']);
                    }
                }
            } elseif (isset($where['values'])) {
                if (is_array($where['values'])) {
                    array_walk_recursive($where['values'], function (&$item, $key) {
                        if ($item instanceof DateTimeInterface) {
                            $item = new UTCDateTime($item);
                        }
                    });
                } elseif ($where['values'] instanceof CarbonPeriod) {
                    $where['values'] = [
                        new UTCDateTime($where['values']->getStartDate()),
                        new UTCDateTime($where['values']->getEndDate()),
                    ];
                }
            }

            // In a sequence of "where" clauses, the logical operator of the
            // first "where" is determined by the 2nd "where".
            // $where['boolean'] = "and", "or", "and not" or "or not"
            if (
                $i === 0 && count($wheres) > 1
                && str_starts_with($where['boolean'], 'and')
                && str_starts_with($wheres[$i + 1]['boolean'], 'or')
            ) {
                $where['boolean'] = 'or' . (str_ends_with($where['boolean'], 'not') ? ' not' : '');
            }

            // We use different methods to compile different wheres.
            $method = 'compileWhere' . $where['type'];
            $result = $this->{$method}($where);

            if (str_ends_with($where['boolean'], 'not')) {
                $result = ['$not' => $result];
            }

            // Wrap the where with an $or operator.
            if (str_starts_with($where['boolean'], 'or')) {
                $result = ['$or' => [$result]];
                // phpcs:ignore Squiz.ControlStructures.ControlSignature.SpaceAfterCloseBrace
            }

            // If there are multiple wheres, we will wrap it with $and. This is needed
            // to make nested wheres work.
            elseif (count($wheres) > 1) {
                $result = ['$and' => [$result]];
            }

            // Merge the compiled where with the others.
            $compiled = array_merge_recursive($compiled, $result);
        }

        return $compiled;
    }

    /**
     * @param  array $where
     *
     * @return array
     */
    protected function compileWhereBasic(array $where): array
    {
        $column   = $where['column'];
        $operator = $where['operator'];
        $value    = $where['value'];

        // Replace like or not like with a Regex instance.
        if (in_array($operator, ['like', 'not like'])) {
            $regex = preg_replace(
                [
                    // Unescaped % are converted to .*
                    // Group consecutive %
                    '#(^|[^\\\])%+#',
                    // Unescaped _ are converted to .
                    // Use positive lookahead to replace consecutive _
                    '#(?<=^|[^\\\\])_#',
                    // Escaped \% or \_ are unescaped
                    '#\\\\\\\(%|_)#',
                ],
                ['$1.*', '$1.', '$1'],
                // Escape any regex reserved characters, so they are matched
                // All backslashes are converted to \\, which are needed in matching regexes.
                preg_quote($value),
            );
            $value = new Regex('^' . $regex . '$', 'i');

            // For inverse like operations, we can just use the $not operator with the Regex
            $operator = $operator === 'like' ? '=' : 'not';
            // phpcs:ignore Squiz.ControlStructures.ControlSignature.SpaceAfterCloseBrace
        }

        // Manipulate regex operations.
        elseif (in_array($operator, ['regex', 'not regex'])) {
            // Automatically convert regular expression strings to Regex objects.
            if (is_string($value)) {
                // Detect the delimiter and validate the preg pattern
                $delimiter = substr($value, 0, 1);
                if (! in_array($delimiter, self::REGEX_DELIMITERS)) {
                    throw new LogicException(sprintf('Missing expected starting delimiter in regular expression "%s", supported delimiters are: %s', $value, implode(' ', self::REGEX_DELIMITERS)));
                }

                $e = explode($delimiter, $value);
                // We don't try to detect if the last delimiter is escaped. This would be an invalid regex.
                if (count($e) < 3) {
                    throw new LogicException(sprintf('Missing expected ending delimiter "%s" in regular expression "%s"', $delimiter, $value));
                }

                // Flags are after the last delimiter
                $flags = end($e);
                // Extract the regex string between the delimiters
                $regstr = substr($value, 1, -1 - strlen($flags));
                $value  = new Regex($regstr, $flags);
            }

            // For inverse regex operations, we can just use the $not operator with the Regex
            $operator = $operator === 'regex' ? '=' : 'not';
        }

        if (! isset($operator) || $operator === '=' || $operator === 'eq') {
            $query = [$column => $value];
        } else {
            $query = [$column => ['$' . $operator => $value]];
        }

        return $query;
    }

    protected function compileWhereNested(array $where): mixed
    {
        return $where['query']->compileWheres();
    }

    protected function compileWhereIn(array $where): array
    {
        return [$where['column'] => ['$in' => array_values($where['values'])]];
    }

    protected function compileWhereNotIn(array $where): array
    {
        return [$where['column'] => ['$nin' => array_values($where['values'])]];
    }

    protected function compileWhereNull(array $where): array
    {
        $where['operator'] = '=';
        $where['value']    = null;

        return $this->compileWhereBasic($where);
    }

    protected function compileWhereNotNull(array $where): array
    {
        $where['operator'] = 'ne';
        $where['value']    = null;

        return $this->compileWhereBasic($where);
    }

    protected function compileWhereBetween(array $where): array
    {
        $column = $where['column'];
        $not    = $where['not'];
        $values = $where['values'];

        if ($not) {
            return [
                '$or' => [
                    [
                        $column => [
                            '$lte' => $values[0],
                        ],
                    ],
                    [
                        $column => [
                            '$gte' => $values[1],
                        ],
                    ],
                ],
            ];
        }

        return [
            $column => [
                '$gte' => $values[0],
                '$lte' => $values[1],
            ],
        ];
    }

    protected function compileWhereDate(array $where): array
    {
        $startOfDay = new UTCDateTime(Carbon::parse($where['value'])->startOfDay());
        $endOfDay   = new UTCDateTime(Carbon::parse($where['value'])->endOfDay());

        return match ($where['operator']) {
            'eq', '=' => [
                $where['column'] => [
                    '$gte' => $startOfDay,
                    '$lte' => $endOfDay,
                ],
            ],
            'ne' => [
                $where['column'] => [
                    '$not' => [
                        '$gte' => $startOfDay,
                        '$lte' => $endOfDay,
                    ],
                ],
            ],
            'lt', 'gte' => [
                $where['column'] => ['$' . $where['operator'] => $startOfDay],
            ],
            'gt', 'lte' => [
                $where['column'] => ['$' . $where['operator'] => $endOfDay],
            ],
        };
    }

    protected function compileWhereMonth(array $where): array
    {
        return [
            '$expr' => [
                '$' . $where['operator'] => [
                    [
                        '$month' => '$' . $where['column'],
                    ],
                    (int) $where['value'],
                ],
            ],
        ];
    }

    protected function compileWhereDay(array $where): array
    {
        return [
            '$expr' => [
                '$' . $where['operator'] => [
                    [
                        '$dayOfMonth' => '$' . $where['column'],
                    ],
                    (int) $where['value'],
                ],
            ],
        ];
    }

    protected function compileWhereYear(array $where): array
    {
        return [
            '$expr' => [
                '$' . $where['operator'] => [
                    [
                        '$year' => '$' . $where['column'],
                    ],
                    (int) $where['value'],
                ],
            ],
        ];
    }

    protected function compileWhereTime(array $where): array
    {
        if (! is_string($where['value']) || ! preg_match('/^[0-2][0-9](:[0-6][0-9](:[0-6][0-9])?)?$/', $where['value'], $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid time format, expected HH:MM:SS, HH:MM or HH, got "%s"', is_string($where['value']) ? $where['value'] : get_debug_type($where['value'])));
        }

        $format = match (count($matches)) {
            1 => '%H',
            2 => '%H:%M',
            3 => '%H:%M:%S',
        };

        return [
            '$expr' => [
                '$' . $where['operator'] => [
                    [
                        '$dateToString' => ['date' => '$' . $where['column'], 'format' => $format],
                    ],
                    $where['value'],
                ],
            ],
        ];
    }

    protected function compileWhereRaw(array $where): mixed
    {
        return $where['sql'];
    }

    /**
     * Set custom options for the query.
     *
     * @return $this
     */
    public function options(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Apply the connection's session to options if it's not already specified.
     */
    private function inheritConnectionOptions(array $options = []): array
    {
        if (! isset($options['session'])) {
            $session = $this->connection->getSession();
            if ($session) {
                $options['session'] = $session;
            }
        }

        return $options;
    }

    /** @inheritdoc */
    public function __call($method, $parameters)
    {
        if ($method === 'unset') {
            return $this->drop(...$parameters);
        }

        return parent::__call($method, $parameters);
    }

    /** @internal This method is not supported by MongoDB. */
    public function toSql()
    {
        throw new BadMethodCallException('This method is not supported by MongoDB. Try "toMql()" instead.');
    }

    /** @internal This method is not supported by MongoDB. */
    public function toRawSql()
    {
        throw new BadMethodCallException('This method is not supported by MongoDB. Try "toMql()" instead.');
    }

    /** @internal This method is not supported by MongoDB. */
    public function whereColumn($first, $operator = null, $second = null, $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function whereFullText($columns, $value, array $options = [], $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function groupByRaw($sql, array $bindings = [])
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function orderByRaw($sql, $bindings = [])
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function unionAll($query)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function union($query, $all = false)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function having($column, $operator = null, $value = null, $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function havingRaw($sql, array $bindings = [], $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function havingBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function whereIntegerInRaw($column, $values, $boolean = 'and', $not = false)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function orWhereIntegerInRaw($column, $values)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function whereIntegerNotInRaw($column, $values, $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function orWhereIntegerNotInRaw($column, $values, $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }
}
