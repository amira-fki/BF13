<?php
namespace BF13\Component\Storage\DoctrineUnit;

use BF13\Component\Storage\StorageHandlerInterface;
use BF13\Component\Storage\StorageRepositoryInterface;

/**
 * @author FYAMANI
 *
 */
class Handler implements StorageHandlerInterface
{
    protected $repository;

    public function __construct(StorageRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * (non-PHPdoc)
     * @see \BF13\Component\Storage\StorageRepositoryInterface::retrieve()
     */
    public function retrieve($index)
    {
        if (is_array($index)) {

            $item = $this->repository->findOneBy($index);

        } else {

            $item = $this->repository->find($index);
        }

        return $item;
    }

    /**
     * (non-PHPdoc)
     * @see \BF13\Component\Storage\StorageRepositoryInterface::retrieveNew()
     */
    public function create($data = array())
    {
        return $this->repository->createEntity($data);
    }
}
