<?php

namespace Rawkode\Eidetic\EventSourcing\DBALEventStore;

use Doctrine\DBAL\Connection;
use Rawkode\Eidetic\EventSourcing\EventSourcedEntity;
use Rawkode\Eidetic\EventSourcing\EventStore\EventStore;
use Rawkode\Eidetic\EventSourcing\EventStore\VersionMismatchException;
use Rawkode\Eidetic\EventSourcing\EventStore\EntityDoesNotExistException;
use Rawkode\Eidetic\EventSourcing\InvalidEventException;

final class DBALEventStore implements EventStore
{
    /**
     * @var string
     */
    private $tableName;

    /**
     * @var Doctrine\DBAL\Connection
     */
    private $dbalConnection;

    /**
     * @param Connection $dbalConnection
     */
    public function __construct(Connection $dbalConnection, $tableName)
    {
        $this->dbalConnection = $dbalConnection;
        $this->tableName = $tableName;
    }

    /**
     * @param EventSourcedEntity $eventSourcedEntity
     */
    public function save(EventSourcedEntity $eventSourcedEntity)
    {
        $this->verifyVersion($eventSourcedEntity);

        $version = $eventSourcedEntity->version();

        try {
            $this->startTransaction();

            foreach ($eventSourcedEntity->stagedEvents() as $event) {
                $this->storeEvent($eventSourcedEntity->identifier(), $event, ++$version);
            }
        } catch (TransactionAlreadyInProgressException $transactionAlreadyInProgressExeception) {
            throw $transactionAlreadyInProgressExeception;
        } catch (InvalidEventException $invalidEventException) {
            $this->abortTransaction();

            throw $invalidEventException;
        }

        $this->completeTransaction();
    }

    /**
     * @param string $entityIdentifier
     *
     * @return array
     */
    public function fetchEntityEvents($entityIdentifier)
    {
        $queryBuilder = $this->dbalConnection->createQueryBuilder();

        $queryBuilder->select('event');
        $queryBuilder->from($this->tableName);
        $queryBuilder->where('entity_identifier', '=', ':entity_identifier');
        $queryBuilder->orderBy('recorded_at', 'ASC');
        $queryBuilder->setParameter('entity_identifier', $entityIdentifier);

        $statement = $queryBuilder->execute();

        $events = [];

        foreach ($statement->fetchAll() as $row) {
            $events[] = unserialize(base64_decode($row['event']));
        }

        return $events;
    }

    /**
     */
    private function startTransaction()
    {
        $this->dbalConnection->beginTransaction();
    }

    /**
     */
    private function abortTransaction()
    {
        $this->dbalConnection->rollBack();
    }

    /**
     */
    private function completeTransaction()
    {
        $this->dbalConnection->commit();
    }

    /**
     * @param string $entityIdentifier
     * @param  $event
     * @param int $version
     */
    private function storeEvent($identifier, $event, $version)
    {
        $this->verifyEventIsAClass($event);
    }

    /**
     * @param EventSourcedEntity $eventSourcedEntity
     */
    private function verifyVersion(EventSourcedEntity $eventSourcedEntity)
    {
        if ($eventSourcedEntity->version() !== $this->entityVersion($eventSourcedEntity->identifier())) {
            throw new VersionMismatchException();
        }
    }

    /**
     * @param string $aggregateIdentifier
     *
     * @return int
     *
     * @throws EntityDoesNotExistException
     */
    private function entityVersion($entityIdentifier)
    {
        $queryBuilder = $this->dbalConnection->createQueryBuilder();

        $queryBuilder->select('COUNT(*)');

        $queryBuilder->from($this->tableName);

        $queryBuilder->where('entity_identifier', '=', ':entity_identifier');

        $queryBuilder->orderBy('recorded_at', 'DESC');

        $queryBuilder->setMaxResults(1);

        $queryBuilder->setParameter('entity_identifier', $entityIdentifier);

        $statement = $queryBuilder->execute();

        if ($statement->rowCount() === 0) {
            throw new EntityDoesNotExistException();
        }

        return $statement->fetchColumn(0);
    }

    /**
     * @param object $event
     *
     * @throws InvalidArgumentException
     */
    private function verifyEventIsAClass($event)
    {
        try {
            if (false === get_class($event)) {
                throw new InvalidEventException();
            }
        } catch (\Exception $exception) {
            throw new InvalidEventException();
        }
    }
}
