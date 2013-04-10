<?php
namespace BF13\Component\DomainConnect;

/**
 * Assistant requête
 *
 * @author FYAMANI
 *
 */
class EntityQuerizer
{
    protected $_params = array('conditions' => array(), 'select' => array());

    protected $_repository;

    public function __construct($repository)
    {
        $this->_repository = $repository;
    }

    /**
     * tableau des conditions
     *
     *     nom_condition => [param1 => value1, ...]
     *
     *
     * @param unknown_type $arg
     * @return \Rff\DomainBundle\Service\Shared\EntityQuerizer
     */
    public function conditions($arg)
    {
        $this->_params['conditions'] = $arg;

        return $this;
    }

    /**
     * Sélection de la colonne de trie
     *
     *
     * @param unknown_type $orderBy
     */
    public function sort($orderBy = array())
    {
        $this->_params['order_by'] = $orderBy;

        return $this;
    }

    /**
     * Pagination du résultat
     *
     * @param unknown_type $offset
     * @param unknown_type $max_result
     * @return \Rff\DomainBundle\Service\Shared\EntityQuerizer
     */
    public function pager($offset = 0, $max_result = 5)
    {
        $this->_params['pager'] = array('offset' => $offset * $max_result, 'max_result' => $max_result);

        return $this;
    }

    /**
     * Liste des colonnes retournées
     *
     * @param unknown_type $fields
     * @return \Rff\DomainBundle\Service\Shared\EntityQuerizer
     */
    public function datafields($fields = array())
    {
        $this->_params['select'] = $fields;

        return $this;
    }

    /**
     * Grouper les résultats
     *
     * @param unknown_type $group_by
     * @return \Rff\DomainBundle\Service\Shared\EntityQuerizer
     */
    public function groupBy($group_by = '')
    {
        $this->_params['group_by'] = $group_by;

        return $this;
    }

    /**
     * Retourne un résultat
     *
     * @return unknown
     */
    public function result()
    {
        $query_builder = $this->_getQueryBuilder();

        $query_builder->setMaxResults(1);

        $results = $query_builder->getQuery()->getResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        return $results[0];
    }

    /**
     * Retourne un tableau de résultats
     *
     */
    public function results($mode = \Doctrine\ORM\Query::HYDRATE_ARRAY)
    {
        return $this->_getQueryBuilder()->getQuery()->getResult($mode);
    }

    /**
     * Retourne le résultat paginé
     *
     * @param unknown_type $offset
     * @param unknown_type $max_result
     * @return multitype:unknown number
     */
    public function resultsWithPager($offset = 0, $max_result = 5)
    {
        $query_builder = $this->_getQueryBuilder();

        //pager
        $this->_repository->pager($query_builder, array('offset' => $offset * $max_result, 'max_result' => $max_result));

        //result
        $results = $query_builder->getQuery()->getResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        //calcul du total
        $total = intval($this->_repository->total($query_builder));

        $max_page = ceil($total / $max_result);

        $current_page = $offset + 1;

        return array(
            'total' => $total,
            'rows' => $results,
            'offset' => $offset,
            'max_result' => $max_result,
            'max_page' => $max_page,
            'current_page' => $current_page,
        );
    }

    protected function _getQueryBuilder()
    {
        if(! $this->_repository instanceOf DomainEntityRepository) {

            throw new \Exception('Le dépôt doit être une instance de DomainEntityRepository');
        }

        $query_builder = $this->_repository->initializeQuery();

        $this->_repository->selectQuery($query_builder, $this->_params['select']);

        $this->_repository->conditionQuery($query_builder, $this->_params['conditions']);

        if (array_key_exists('group_by', $this->_params)) {

            $query_builder->groupBy($this->_params['group_by']);
        }

        if (array_key_exists('order_by', $this->_params)) {

            $this->_repository->orderBy($query_builder, $this->_params['order_by']);
        }

        $this->_repository->joinQuery($query_builder);

        return $query_builder;
    }
}
