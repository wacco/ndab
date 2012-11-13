<?php

/**
 * This file is part of the Ndab
 *
 * Copyright (c) 2012 Jan Skrasek (http://jan.skrasek.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Ndab;

use Nette,
	Nette\Database\Table;



/**
 * Ndab Grouped selection
 *
 * @author  Jan Skrasek
 */
class GroupedSelection extends Selection
{
	/** @var string */
	protected $table;

	/** @var string */
	protected $rowClass;

	/** @var Selection referenced table */
	protected $refTable;

	/** @var string grouping column name */
	protected $column;

	/** @var int primary key */
	protected $active;

	/** @var Manager */
	protected $manager;

	/**
	 * Creates filtered and grouped table representation.
	 * @param  Selection  $refTable
	 * @param  string  database table name
	 * @param  string  joining column
	 */
	public function __construct(Selection $refTable, $table, $column, Manager $manager)
	{
		parent::__construct($refTable->connection, $this->table = $table, $manager);
		$this->refTable = $refTable;
		$this->column = $column;
	}



	/**
	 * Sets active group.
	 * @internal
	 * @param  int  primary key of grouped rows
	 * @return GroupedSelection
	 */
	public function setActive($active)
	{
		$this->active = $active;
		return $this;
	}



	/** @deprecated */
	public function through($column)
	{
		trigger_error(__METHOD__ . '() is deprecated; use ' . __CLASS__ . '::related("' . $this->name . '", "' . $column . '") instead.', E_USER_WARNING);
		$this->column = $column;
		$this->delimitedColumn = $this->refTable->connection->getSupplementalDriver()->delimite($this->column);
		return $this;
	}



	public function select($columns)
	{
		if (!$this->sqlBuilder->getSelect()) {
			$this->sqlBuilder->addSelect("$this->name.$this->column");
		}

		return parent::select($columns);
	}



	public function order($columns)
	{
		if (!$this->sqlBuilder->getOrder()) {
			// improve index utilization
			$this->sqlBuilder->addOrder("$this->name.$this->column" . (preg_match('~\\bDESC$~i', $columns) ? ' DESC' : ''));
		}

		return parent::order($columns);
	}



	/********************* aggregations ****************d*g**/



	public function aggregation($function)
	{
		$aggregation = & $this->getRefTable($refPath)->aggregation[$refPath . $function . $this->sqlBuilder->buildSelectQuery() . json_encode($this->sqlBuilder->getParameters())];

		if ($aggregation === NULL) {
			$aggregation = array();

			$selection = $this->createSelectionInstance();
			$selection->getSqlBuilder()->importConditions($this->getSqlBuilder());
			$selection->select($function);
			$selection->select("$this->name.$this->column");
			$selection->group("$this->name.$this->column");

			foreach ($selection as $row) {
				$aggregation[$row[$this->column]] = $row;
			}
		}

		if (isset($aggregation[$this->active])) {
			foreach ($aggregation[$this->active] as $val) {
				return $val;
			}
		}
	}



	public function count($column = NULL)
	{
		$return = parent::count($column);
		return isset($return) ? $return : 0;
	}



	/********************* internal ****************d*g**/



	protected function execute()
	{
		if ($this->rows !== NULL) {
			return;
		}

		$hash = md5($this->sqlBuilder->buildSelectQuery() . json_encode($this->sqlBuilder->getParameters()));

		$referencing = & $this->getRefTable($refPath)->referencing[$refPath . $hash];
		$this->rows = & $referencing['rows'];
		$this->referenced = & $referencing['refs'];
		$this->accessed = & $referencing['accessed'];
		$refData = & $referencing['data'];

		if ($refData === NULL) {
			$limit = $this->sqlBuilder->getLimit();
			$rows = count($this->refTable->rows);
			if ($limit && $rows > 1) {
				$this->sqlBuilder->setLimit(NULL, NULL);
			}
			parent::execute();
			$this->sqlBuilder->setLimit($limit, NULL);
			$refData = array();
			$offset = array();
			foreach ($this->rows as $key => $row) {
				$ref = & $refData[$row[$this->column]];
				$skip = & $offset[$row[$this->column]];
				if ($limit === NULL || $rows <= 1 || (count($ref) < $limit && $skip >= $this->sqlBuilder->getOffset())) {
					$ref[$key] = $row;
				} else {
					unset($this->rows[$key]);
				}
				$skip++;
				unset($ref, $skip);
			}
		}

		$this->data = & $refData[$this->active];
		if ($this->data === NULL) {
			$this->data = array();
		} else {
			foreach ($this->data as $row) {
				$row->setTable($this); // injects correct parent GroupedSelection
			}
			reset($this->data);
		}
	}



	protected function getRefTable(& $refPath)
	{
		$refObj = $this->refTable;
		$refPath = $this->name . '.';
		while ($refObj instanceof GroupedSelection) {
			$refPath .= $refObj->name . '.';
			$refObj = $refObj->refTable;
		}

		return $refObj;
	}



	/********************* manipulation ****************d*g**/



	public function insert($data)
	{
		if ($data instanceof \Traversable && !$data instanceof Selection) {
			$data = iterator_to_array($data);
		}

		if (Nette\Utils\Validators::isList($data)) {
			foreach (array_keys($data) as $key) {
				$data[$key][$this->column] = $this->active;
			}
		} else {
			$data[$this->column] = $this->active;
		}

		return parent::insert($data);
	}



	public function update($data)
	{
		$builder = $this->sqlBuilder;

		$this->sqlBuilder = new Table\SqlBuilder($this);
		$this->where($this->column, $this->active);
		$return = parent::update($data);

		$this->sqlBuilder = $builder;
		return $return;
	}



	public function delete()
	{
		$builder = $this->sqlBuilder;

		//TODO: toto som musel zakomentovat. neviem ci je to OK
		//$this->sqlBuilder = new Table\SqlBuilder($this);
		//$this->where($this->column, $this->active);
		$return = parent::delete();

		$this->sqlBuilder = $builder;
		return $return;
	}



	public function getTable()
	{
		return $this->table;
	}



	public function setRowClass($class)
	{
		$this->rowClass = $class;
		return $this;
	}



	public function getRowClass()
	{
		return $this->rowClass;
	}



	protected function createRow(array $row)
	{
		return $this->refTable->manager->initEntity($row, $this);
	}



	protected function createSelectionInstance($table = NULL)
	{
		return new Selection($this->connection, $table ?: $this->table, $this->refTable->manager);
	}



	protected function createGroupedSelectionInstance($table, $column)
	{
		return new GroupedSelection($this, $table, $column, $this->refTable->manager);
	}

}
