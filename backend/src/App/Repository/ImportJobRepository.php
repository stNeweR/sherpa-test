<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\Import\ImportJobState;
use App\Entity\ImportJob;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<ImportJob>
 */
class ImportJobRepository extends EntityRepository
{
    public function findJob(string $id): ?ImportJob
    {
        return $this->find($id);
    }

    /** @return list<ImportJob> */
    public function findRecent(int $limit = 20): array
    {
        /** @var list<ImportJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->orderBy('j.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $jobs;
    }

    /** @return list<ImportJob> */
    public function findByState(ImportJobState $state): array
    {
        /** @var list<ImportJob> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->where('j.state = :state')
            ->setParameter('state', $state->value)
            ->orderBy('j.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $jobs;
    }
}
