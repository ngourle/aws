<?php

namespace AsyncAws\DynamoDb\Tests\Integration;

use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\Core\Test\TestCase;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\KeyType;
use AsyncAws\DynamoDb\Enum\ProjectionType;
use AsyncAws\DynamoDb\Input\BatchGetItemInput;
use AsyncAws\DynamoDb\Input\BatchWriteItemInput;
use AsyncAws\DynamoDb\Input\CreateTableInput;
use AsyncAws\DynamoDb\Input\DeleteItemInput;
use AsyncAws\DynamoDb\Input\DeleteTableInput;
use AsyncAws\DynamoDb\Input\DescribeTableInput;
use AsyncAws\DynamoDb\Input\ExecuteStatementInput;
use AsyncAws\DynamoDb\Input\GetItemInput;
use AsyncAws\DynamoDb\Input\ListTablesInput;
use AsyncAws\DynamoDb\Input\PutItemInput;
use AsyncAws\DynamoDb\Input\QueryInput;
use AsyncAws\DynamoDb\Input\ScanInput;
use AsyncAws\DynamoDb\Input\TransactWriteItemsInput;
use AsyncAws\DynamoDb\Input\UpdateItemInput;
use AsyncAws\DynamoDb\Input\UpdateTableInput;
use AsyncAws\DynamoDb\Input\UpdateTimeToLiveInput;
use AsyncAws\DynamoDb\ValueObject\AttributeDefinition;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\KeysAndAttributes;
use AsyncAws\DynamoDb\ValueObject\KeySchemaElement;
use AsyncAws\DynamoDb\ValueObject\LocalSecondaryIndex;
use AsyncAws\DynamoDb\ValueObject\Projection;
use AsyncAws\DynamoDb\ValueObject\ProvisionedThroughput;
use AsyncAws\DynamoDb\ValueObject\Put;
use AsyncAws\DynamoDb\ValueObject\PutRequest;
use AsyncAws\DynamoDb\ValueObject\Tag;
use AsyncAws\DynamoDb\ValueObject\TimeToLiveSpecification;
use AsyncAws\DynamoDb\ValueObject\TransactWriteItem;
use AsyncAws\DynamoDb\ValueObject\WriteRequest;

class DynamoDbClientTest extends TestCase
{
    /**
     * @var string
     */
    private $tableName;

    /**
     * @var DynamoDbClient|null
     */
    private $client;

    /**
     * @see https://docs.aws.amazon.com/amazondynamodb/latest/APIReference/API_CreateTable.html#API_CreateTable_Examples
     * @see https://docs.aws.amazon.com/amazondynamodb/latest/APIReference/API_PutItem.html#API_PutItem_Examples
     */
    public function setUp(): void
    {
        $client = $this->getClient();

        $this->tableName = 'Thread' . rand(1, 100000000);
        $input = new CreateTableInput([
            'AttributeDefinitions' => [
                new AttributeDefinition(['AttributeName' => 'ForumName', 'AttributeType' => 'S']),
                new AttributeDefinition(['AttributeName' => 'Subject', 'AttributeType' => 'S']),
                new AttributeDefinition(['AttributeName' => 'LastPostDateTime', 'AttributeType' => 'S']),
            ],
            'TableName' => $this->tableName,
            'KeySchema' => [
                new KeySchemaElement(['AttributeName' => 'ForumName', 'KeyType' => KeyType::HASH]),
                new KeySchemaElement(['AttributeName' => 'Subject', 'KeyType' => KeyType::RANGE]),
            ],
            'LocalSecondaryIndexes' => [
                new LocalSecondaryIndex([
                    'IndexName' => 'LastPostIndex',
                    'KeySchema' => [
                        new KeySchemaElement(['AttributeName' => 'ForumName', 'KeyType' => KeyType::HASH]),
                        new KeySchemaElement(['AttributeName' => 'LastPostDateTime', 'KeyType' => KeyType::RANGE]),
                    ],
                    'Projection' => new Projection(['ProjectionType' => ProjectionType::KEYS_ONLY]),
                ]),
            ],
            'ProvisionedThroughput' => new ProvisionedThroughput([
                'ReadCapacityUnits' => 5,
                'WriteCapacityUnits' => 5,
            ]),
            'Tags' => [
                new Tag(['Key' => 'Owner', 'Value' => 'BlueTeam']),
            ],
        ]);
        $result = $client->CreateTable($input);

        $result->resolve();

        // Add some data

        $client->PutItem([
            'TableName' => $this->tableName,
            'Item' => [
                'LastPostDateTime' => ['S' => '201303190422'],
                'Tags' => ['SS' => ['Update', 'Multiple Items', 'HelpMe']],
                'ForumName' => ['S' => 'Amazon DynamoDB'],
                'Message' => ['S' => 'I want to update multiple items in a single call. What\'s the best way to do that?'],
                'Subject' => ['S' => 'How do I update multiple items?'],
                'LastPostedBy' => ['S' => 'fred@example.com'],
            ],
            'ConditionExpression' => 'ForumName <> :f and Subject <> :s',
            'ExpressionAttributeValues' => [
                ':f' => ['S' => 'Amazon DynamoDB'],
                ':s' => ['S' => 'How do I update multiple items?'],
            ],
        ]);

        $client->PutItem([
            'TableName' => $this->tableName,
            'Item' => [
                'LastPostDateTime' => ['S' => '201303190422'],
                'Tags' => ['SS' => ['Update', 'Multiple Items', 'HelpMe']],
                'ForumName' => ['S' => 'Amazon DynamoDB'],
                'Message' => ['S' => 'What is the maximum number of items?'],
                'Subject' => ['S' => 'Maximum number of items?'],
                'LastPostedBy' => ['S' => 'fred@example.com'],
            ],
            'ConditionExpression' => 'ForumName <> :f and Subject <> :s',
            'ExpressionAttributeValues' => [
                ':f' => ['S' => 'Amazon DynamoDB'],
                ':s' => ['S' => 'How do I update multiple items?'],
            ],
        ]);
    }

    public function tearDown(): void
    {
        $client = $this->getClient();

        $input = new DeleteTableInput(['TableName' => $this->tableName]);
        $result = $client->DeleteTable($input);

        $result->resolve();
    }

    public function testBatchGetItem(): void
    {
        $client = $this->getClient();

        $input = new BatchGetItemInput([
            'RequestItems' => [
                $this->tableName => new KeysAndAttributes([
                    'Keys' => [
                        [
                            'ForumName' => new AttributeValue(['S' => 'Amazon DynamoDB']),
                            'Subject' => new AttributeValue(['S' => 'Maximum number of items?']),
                        ],
                    ],
                    'ProjectionExpression' => 'Tags, Message',
                ]),
            ],
            'ReturnConsumedCapacity' => 'TOTAL',
        ]);
        $result = $client->BatchGetItem($input);

        $result->resolve();

        self::assertEmpty($result->getUnprocessedKeys());
        $threadResult = $result->getResponses()[$this->tableName];
        self::assertArrayHasKey(0, $threadResult);
        self::assertArrayHasKey('Message', $threadResult[0]);
        self::assertEquals('What is the maximum number of items?', $threadResult[0]['Message']->getS());
    }

    public function testBatchWriteItem(): void
    {
        $client = $this->getClient();

        $input = new BatchWriteItemInput([
            'RequestItems' => [$this->tableName => [new WriteRequest([
                'PutRequest' => new PutRequest([
                    'Item' => [
                        'LastPostDateTime' => ['S' => '201303190422'],
                        'Tags' => ['SS' => ['Update', 'Multiple Items', 'HelpMe']],
                        'ForumName' => ['S' => 'Amazon DynamoDB'],
                        'Message' => ['S' => 'What is the maximum number of items?'],
                        'Subject' => ['S' => 'Maximum number of items?'],
                        'LastPostedBy' => ['S' => 'fred@example.com'],
                    ],
                ]),
            ])]],
            'ReturnConsumedCapacity' => 'TOTAL',
        ]);
        $result = $client->BatchWriteItem($input);

        $result->resolve();

        $capacity = $result->getConsumedCapacity()[0];
        self::assertEquals($this->tableName, $capacity->getTableName());
    }

    public function testCreateTable(): void
    {
        $client = $this->getClient();

        $input = new CreateTableInput([
            'TableName' => 'demo',
            'AttributeDefinitions' => [
                new AttributeDefinition(['AttributeName' => 'ForumName', 'AttributeType' => 'S']),
            ],
            'KeySchema' => [
                new KeySchemaElement(['AttributeName' => 'ForumName', 'KeyType' => KeyType::HASH]),
            ],
            'ProvisionedThroughput' => new ProvisionedThroughput([
                'ReadCapacityUnits' => 5,
                'WriteCapacityUnits' => 5,
            ]),
        ]);

        $result = $client->createTable($input);
        $result->resolve();

        try {
            self::assertSame('arn:aws:dynamodb:us-east-1:000000000000:table/demo', $result->getTableDescription()->getTableArn());
            self::assertSame(0, $result->getTableDescription()->getItemCount());
            self::assertTrue($client->tableExists(['TableName' => 'demo'])->isSuccess());
        } finally {
            $this->getClient()->deleteTable(['TableName' => 'demo']);
        }
    }

    public function testDeleteItem(): void
    {
        $client = $this->getClient();

        $input = new DeleteItemInput([
            'TableName' => $this->tableName,
            'Key' => [
                'ForumName' => ['S' => 'Amazon DynamoDB'],
                'Subject' => ['S' => 'How do I update multiple items?'],
            ],
            'ReturnValues' => 'ALL_OLD',
            'ConditionExpression' => 'attribute_not_exists(Replies)',
        ]);
        $result = $client->DeleteItem($input);

        $result->resolve();

        self::assertEquals(200, $result->info()['status']);
    }

    public function testDeleteTable(): void
    {
        $client = $this->getClient();

        $input = new CreateTableInput([
            'TableName' => 'demo',
            'AttributeDefinitions' => [
                new AttributeDefinition(['AttributeName' => 'ForumName', 'AttributeType' => 'S']),
            ],
            'KeySchema' => [
                new KeySchemaElement(['AttributeName' => 'ForumName', 'KeyType' => KeyType::HASH]),
            ],
            'ProvisionedThroughput' => new ProvisionedThroughput([
                'ReadCapacityUnits' => 5,
                'WriteCapacityUnits' => 5,
            ]),
        ]);

        $client->createTable($input);

        $result = $client->deleteTable(['TableName' => 'demo']);
        $result->resolve();

        self::assertFalse($client->tableExists(['TableName' => 'demo'])->isSuccess());
    }

    public function testDescribeTable(): void
    {
        $client = $this->getClient();

        $input = new DescribeTableInput([
            'TableName' => $this->tableName,
        ]);
        $result = $client->DescribeTable($input);

        self::assertEquals($this->tableName, $result->getTable()->getTableName());
    }

    public function testExecuteStatement(): void
    {
        $client = $this->getClient();

        $input = new ExecuteStatementInput([
            'Statement' => "SELECT * FROM \"{$this->tableName}\" WHERE ForumName = ?",
            'Parameters' => [new AttributeValue([
                'S' => 'Amazon DynamoDB',
            ])],
            'ConsistentRead' => true,
        ]);
        $result = $client->executeStatement($input);

        $result->resolve();

        self::assertSame(2, \count($result->getItems()));
    }

    public function testGetItem(): void
    {
        $client = $this->getClient();

        $input = new GetItemInput([
            'TableName' => $this->tableName,
            'Key' => [
                'ForumName' => ['S' => 'Amazon DynamoDB'],
                'Subject' => ['S' => 'How do I update multiple items?'],
            ],
            'ConsistentRead' => true,
            'ReturnConsumedCapacity' => 'TOTAL',
            'ProjectionExpression' => 'LastPostDateTime, Message, Tags',
        ]);
        $result = $client->GetItem($input);

        self::assertArrayHasKey('Message', $result->getItem());
        self::assertEquals('I want to update multiple items in a single call. What\'s the best way to do that?', $result->getItem()['Message']->getS());
    }

    public function testListTables(): void
    {
        $client = $this->getClient();

        $input = new ListTablesInput([
            'ExclusiveStartTableName' => 'Thr',
            'Limit' => 5,
        ]);
        $result = $client->ListTables($input);

        $names = iterator_to_array($result->getTableNames(true));
        self::assertTrue(\count($names) >= 0);
    }

    public function testPutItem(): void
    {
        $client = $this->getClient();

        $input = new PutItemInput([
            'TableName' => $this->tableName,
            'Item' => [
                'LastPostDateTime' => ['S' => '201303190422'],
                'Tags' => ['SS' => ['Update', 'Multiple Items', 'HelpMe']],
                'ForumName' => ['S' => 'Amazon DynamoDB'],
                'Message' => ['S' => 'I want to update multiple items in a single call. What\'s the best way to do that?'],
                'Subject' => ['S' => 'How do I update multiple items?'],
                'LastPostedBy' => ['S' => 'fred@example.com'],
            ],
        ]);

        $result = $client->putItem($input);
        self::assertSame(1.0, $result->getConsumedCapacity()->getCapacityUnits());
    }

    public function testQuery(): void
    {
        $client = $this->getClient();

        $input = new QueryInput([
            'TableName' => $this->tableName,
            'ConsistentRead' => true,
            'KeyConditionExpression' => 'ForumName = :val',
            'ExpressionAttributeValues' => [':val' => ['S' => 'Amazon DynamoDB']],
        ]);
        $result = $client->Query($input);

        self::assertSame(2, $result->getCount());
        self::assertSame(2, $result->getScannedCount());
    }

    public function testScan(): void
    {
        $client = $this->getClient();

        $input = new ScanInput([
            'TableName' => $this->tableName,
            'ReturnConsumedCapacity' => 'TOTAL',
        ]);
        $result = $client->Scan($input);

        self::assertSame(2, $result->getCount());
        self::assertSame(2, $result->getScannedCount());
    }

    public function testTableExists(): void
    {
        $client = $this->getClient();

        $input = new DescribeTableInput([
            'TableName' => $this->tableName,
        ]);

        self::assertTrue($client->tableExists($input)->isSuccess());
        self::assertFalse($client->tableExists(['TableName' => 'does-not-exists'])->isSuccess());
    }

    public function testTableNotExists(): void
    {
        $client = $this->getClient();

        $input = new DescribeTableInput([
            'TableName' => $this->tableName,
        ]);

        self::assertFalse($client->tableNotExists($input)->isSuccess());
        self::assertTrue($client->tableNotExists(['TableName' => 'does-not-exists'])->isSuccess());
    }

    public function testTransactWriteItems(): void
    {
        $client = $this->getClient();

        $input = new TransactWriteItemsInput([
            'TransactItems' => [
                new TransactWriteItem([
                    'Put' => new Put([
                        'TableName' => $this->tableName,
                        'Item' => [
                            'LastPostDateTime' => ['S' => '201303190422'],
                            'Tags' => ['SS' => ['Update', 'Multiple Items', 'HelpMe']],
                            'ForumName' => ['S' => 'Amazon DynamoDB'],
                            'Message' => ['S' => 'I want to update multiple items in a single call. What\'s the best way to do that?'],
                            'Subject' => ['S' => 'How do I update multiple items?'],
                            'LastPostedBy' => ['S' => 'fred@example.com'],
                        ],
                    ]),
                ]),
            ],
            'ReturnConsumedCapacity' => 'TOTAL',
            'ClientRequestToken' => 'QWERTYUIOPQWERTYUIOP',
        ]);
        $result = $client->transactWriteItems($input);

        self::assertCount(1, $result->getConsumedCapacity());
        self::assertSame(4.0, $result->getConsumedCapacity()[0]->getCapacityUnits());
    }

    public function testUpdateItem(): void
    {
        $client = $this->getClient();

        $input = new UpdateItemInput([
            'TableName' => $this->tableName,
            'Key' => [
                'ForumName' => ['S' => 'Amazon DynamoDB'],
                'Subject' => ['S' => 'Maximum number of items?'],
            ],
            'UpdateExpression' => 'set LastPostedBy = :val1',
            'ConditionExpression' => 'LastPostedBy = :val2',
            'ExpressionAttributeValues' => [
                ':val1' => ['S' => 'alice@example.com'],
                ':val2' => ['S' => 'fred@example.com'],
            ],
            'ReturnValues' => 'ALL_NEW',
        ]);
        $result = $client->UpdateItem($input);

        $result->resolve();
        self::assertEquals(200, $result->info()['status']);
    }

    public function testUpdateItemWithList(): void
    {
        $client = $this->getClient();
        $input = new UpdateItemInput([
            'TableName' => $this->tableName,
            'Key' => [
                'ForumName' => ['S' => 'Amazon DynamoDB'],
                'Subject' => ['S' => 'Maximum number of items?'],
            ],
            'UpdateExpression' => 'SET Answers = list_append(if_not_exists(Answers, :emptyList), :newAnswer)',
            'ExpressionAttributeValues' => [
                ':newAnswer' => ['L' => [['S' => 'My answer']]],
                ':emptyList' => ['L' => []],
            ],
            'ReturnValues' => 'ALL_NEW',
        ]);
        $result = $client->UpdateItem($input);

        $result->resolve();
        self::assertEquals(200, $result->info()['status']);
    }

    public function testUpdateTable(): void
    {
        $client = $this->getClient();

        $input = new UpdateTableInput([
            'TableName' => $this->tableName,
            'ProvisionedThroughput' => new ProvisionedThroughput([
                'ReadCapacityUnits' => 7,
                'WriteCapacityUnits' => 8,
            ]),
        ]);
        $result = $client->UpdateTable($input);

        self::assertEquals(7, $result->getTableDescription()->getProvisionedThroughput()->getReadCapacityUnits());
        self::assertEquals(8, $result->getTableDescription()->getProvisionedThroughput()->getWriteCapacityUnits());
    }

    public function testUpdateTimeToLive(): void
    {
        $client = $this->getClient();

        $input = new UpdateTimeToLiveInput([
            'TableName' => $this->tableName,
            'TimeToLiveSpecification' => new TimeToLiveSpecification([
                'Enabled' => true,
                'AttributeName' => 'attribute',
            ]),
        ]);
        $result = $client->updateTimeToLive($input);

        $result->resolve();

        self::assertTrue($result->getTimeToLiveSpecification()->getEnabled());
        self::assertSame('attribute', $result->getTimeToLiveSpecification()->getAttributeName());
    }

    public function testDiscoveredEndpoint(): void
    {
        $client = new DynamoDbClient([
            'endpoint' => 'http://localhost:4575',
            'endpointDiscoveryEnabled' => true,
        ], new Credentials('aws_id', 'aws_secret'));

        $input = new ListTablesInput([
            'ExclusiveStartTableName' => 'Thr',
            'Limit' => 5,
        ]);
        $result = $client->ListTables($input);

        $names = iterator_to_array($result->getTableNames(true));
        self::assertTrue(\count($names) >= 0);
    }

    private function getClient(): DynamoDbClient
    {
        if ($this->client instanceof DynamoDbClient) {
            return $this->client;
        }

        return $this->client = new DynamoDbClient([
            'endpoint' => 'http://localhost:4575',
        ], new Credentials('aws_id', 'aws_secret'));
    }
}
