<?php
class DAO_Task extends C4_ORMHelper {
	const ID = 'id';
	const TITLE = 'title';
	const WORKER_ID = 'worker_id';
	const UPDATED_DATE = 'updated_date';
	const DUE_DATE = 'due_date';
	const IS_COMPLETED = 'is_completed';
	const COMPLETED_DATE = 'completed_date';

	static function create($fields) {
		$db = DevblocksPlatform::getDatabaseService();
		
		$id = $db->GenID('task_seq');
		
		$sql = sprintf("INSERT INTO task (id) ".
			"VALUES (%d)",
			$id
		);
		$db->Execute($sql);
		
		self::update($id, $fields);
		
		// New task
	    $eventMgr = DevblocksPlatform::getEventService();
	    $eventMgr->trigger(
	        new Model_DevblocksEvent(
	            'task.create',
                array(
                    'task_id' => $id,
                	'fields' => $fields,
                )
            )
	    );
		
		return $id;
	}
	
	static function update($ids, $fields) {
		parent::_update($ids, 'task', $fields);
	}
	
	static function updateWhere($fields, $where) {
		parent::_updateWhere('task', $fields, $where);
	}
	
	/**
	 * @param string $where
	 * @return Model_Task[]
	 */
	static function getWhere($where=null) {
		$db = DevblocksPlatform::getDatabaseService();
		
		$sql = "SELECT id, title, worker_id, due_date, updated_date, is_completed, completed_date ".
			"FROM task ".
			(!empty($where) ? sprintf("WHERE %s ",$where) : "").
			"ORDER BY id asc";
		$rs = $db->Execute($sql);
		
		return self::_getObjectsFromResult($rs);
	}

	/**
	 * @param integer $id
	 * @return Model_Task	 */
	static function get($id) {
		$objects = self::getWhere(sprintf("%s = %d",
			self::ID,
			$id
		));
		
		if(isset($objects[$id]))
			return $objects[$id];
		
		return null;
	}
	
	/**
	 * @param resource $rs
	 * @return Model_Task[]
	 */
	static private function _getObjectsFromResult($rs) {
		$objects = array();
		
		while($row = mysql_fetch_assoc($rs)) {
			$object = new Model_Task();
			$object->id = $row['id'];
			$object->title = $row['title'];
			$object->worker_id = $row['worker_id'];
			$object->updated_date = $row['updated_date'];
			$object->due_date = $row['due_date'];
			$object->is_completed = $row['is_completed'];
			$object->completed_date = $row['completed_date'];
			$objects[$object->id] = $object;
		}
		
		mysql_free_result($rs);
		
		return $objects;
	}
	
	/**
	 * Enter description here...
	 *
	 * @param array $ids
	 */
	static function delete($ids) {
		if(!is_array($ids)) $ids = array($ids);
		$db = DevblocksPlatform::getDatabaseService();
		
		if(empty($ids))
			return;
		
		$ids_list = implode(',', $ids);
		
		// Tasks
		$db->Execute(sprintf("DELETE QUICK FROM task WHERE id IN (%s)", $ids_list));
		
		// Context links
		DAO_ContextLink::delete(CerberusContexts::CONTEXT_TASK, $ids);
		
		// Custom fields
		DAO_CustomFieldValue::deleteBySourceIds(ChCustomFieldSource_Task::ID, $ids);
		
		// Notes
		DAO_Note::deleteBySourceIds(ChNotesSource_Task::ID, $ids);
		
		return true;
	}

    /**
     * Enter description here...
     *
     * @param DevblocksSearchCriteria[] $params
     * @param integer $limit
     * @param integer $page
     * @param string $sortBy
     * @param boolean $sortAsc
     * @param boolean $withCounts
     * @return array
     */
    static function search($columns, $params, $limit=10, $page=0, $sortBy=null, $sortAsc=null, $withCounts=true) {
		$db = DevblocksPlatform::getDatabaseService();
		$fields = SearchFields_Task::getFields();
		
		// Sanitize
		if(!isset($fields[$sortBy]))
			$sortBy=null;
		
        list($tables,$wheres) = parent::_parseSearchParams($params, $columns, $fields,$sortBy);
		$start = ($page * $limit); // [JAS]: 1-based [TODO] clean up + document
		
		$select_sql = sprintf("SELECT ".
			"t.id as %s, ".
			"t.updated_date as %s, ".
			"t.due_date as %s, ".
			"t.is_completed as %s, ".
			"t.completed_date as %s, ".
			"t.title as %s, ".
			"t.worker_id as %s ",
//			"o.name as %s ".
			    SearchFields_Task::ID,
			    SearchFields_Task::UPDATED_DATE,
			    SearchFields_Task::DUE_DATE,
			    SearchFields_Task::IS_COMPLETED,
			    SearchFields_Task::COMPLETED_DATE,
			    SearchFields_Task::TITLE,
			    SearchFields_Task::WORKER_ID
			 );
		
		$join_sql = 
			"FROM task t ";
//			"LEFT JOIN contact_org o ON (o.id=a.contact_org_id) "

			// [JAS]: Dynamic table joins
//			(isset($tables['o']) ? "LEFT JOIN contact_org o ON (o.id=a.contact_org_id)" : " ").
//			(isset($tables['mc']) ? "INNER JOIN message_content mc ON (mc.message_id=m.id)" : " ").

		// Custom field joins
		list($select_sql, $join_sql, $has_multiple_values) = self::_appendSelectJoinSqlForCustomFieldTables(
			$tables,
			$params,
			't.id',
			$select_sql,
			$join_sql
		);
		
		$where_sql = "".
			(!empty($wheres) ? sprintf("WHERE %s ",implode(' AND ',$wheres)) : "");
			
		$sort_sql =	(!empty($sortBy) ? sprintf("ORDER BY %s %s ",$sortBy,($sortAsc || is_null($sortAsc))?"ASC":"DESC") : " ");
		
		$sql = 
			$select_sql.
			$join_sql.
			$where_sql.
			($has_multiple_values ? 'GROUP BY t.id ' : '').
			$sort_sql;
		
		$rs = $db->SelectLimit($sql,$limit,$start) or die(__CLASS__ . '('.__LINE__.')'. ':' . $db->ErrorMsg()); 
		
		$results = array();
		
		while($row = mysql_fetch_assoc($rs)) {
			$result = array();
			foreach($row as $f => $v) {
				$result[$f] = $v;
			}
			$id = intval($row[SearchFields_Task::ID]);
			$results[$id] = $result;
		}

		// [JAS]: Count all
		$total = -1;
		if($withCounts) {
			$count_sql = 
				($has_multiple_values ? "SELECT COUNT(DISTINCT t.id) " : "SELECT COUNT(t.id) ").
				$join_sql.
				$where_sql;
			$total = $db->GetOne($count_sql);
		}
		
		mysql_free_result($rs);
		
		return array($results,$total);
    }	
	
};

class SearchFields_Task implements IDevblocksSearchFields {
	// Task
	const ID = 't_id';
	const UPDATED_DATE = 't_updated_date';
	const DUE_DATE = 't_due_date';
	const IS_COMPLETED = 't_is_completed';
	const COMPLETED_DATE = 't_completed_date';
	const TITLE = 't_title';
	const WORKER_ID = 't_worker_id';
	
	/**
	 * @return DevblocksSearchField[]
	 */
	static function getFields() {
		$translate = DevblocksPlatform::getTranslationService();
		
		$columns = array(
			self::ID => new DevblocksSearchField(self::ID, 't', 'id', $translate->_('task.id')),
			self::UPDATED_DATE => new DevblocksSearchField(self::UPDATED_DATE, 't', 'updated_date', $translate->_('task.updated_date')),
			self::TITLE => new DevblocksSearchField(self::TITLE, 't', 'title', $translate->_('task.title')),
			self::IS_COMPLETED => new DevblocksSearchField(self::IS_COMPLETED, 't', 'is_completed', $translate->_('task.is_completed')),
			self::DUE_DATE => new DevblocksSearchField(self::DUE_DATE, 't', 'due_date', $translate->_('task.due_date')),
			self::COMPLETED_DATE => new DevblocksSearchField(self::COMPLETED_DATE, 't', 'completed_date', $translate->_('task.completed_date')),
			self::WORKER_ID => new DevblocksSearchField(self::WORKER_ID, 't', 'worker_id', $translate->_('task.worker_id')),
		);
		
		// Custom Fields
		$fields = DAO_CustomField::getBySource(ChCustomFieldSource_Task::ID);
		if(is_array($fields))
		foreach($fields as $field_id => $field) {
			$key = 'cf_'.$field_id;
			$columns[$key] = new DevblocksSearchField($key,$key,'field_value',$field->name);
		}
		
		// Sort by label (translation-conscious)
		uasort($columns, create_function('$a, $b', "return strcasecmp(\$a->db_label,\$b->db_label);\n"));
		
		return $columns;
	}
};

class Model_Task {
	public $id;
	public $title;
	public $worker_id;
	public $created;
	public $due_date;
	public $is_completed;
	public $completed_date;
	public $updated_date;
};

class View_Task extends C4_AbstractView {
	const DEFAULT_ID = 'tasks';
	const DEFAULT_TITLE = 'Tasks';

	function __construct() {
		$this->id = self::DEFAULT_ID;
		$this->name = self::DEFAULT_TITLE;
		$this->renderLimit = 25;
		$this->renderSortBy = SearchFields_Task::DUE_DATE;
		$this->renderSortAsc = true;

		$this->view_columns = array(
			SearchFields_Task::UPDATED_DATE,
			SearchFields_Task::DUE_DATE,
			SearchFields_Task::WORKER_ID,
			);
		
		$this->params = array(
			SearchFields_Task::IS_COMPLETED => new DevblocksSearchCriteria(SearchFields_Task::IS_COMPLETED,'=',0),
		);
	}

	function getData() {
		$objects = DAO_Task::search(
			$this->view_columns,
			$this->params,
			$this->renderLimit,
			$this->renderPage,
			$this->renderSortBy,
			$this->renderSortAsc,
			$this->renderTotal
		);
		return $objects;
	}

	function render() {
		$this->_sanitize();
		
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('id', $this->id);
		$tpl->assign('view', $this);

		$workers = DAO_Worker::getAll();
		$tpl->assign('workers', $workers);

		$tpl->assign('timestamp_now', time());

		// Pull the results so we can do some row introspection
		$results = $this->getData();
		$tpl->assign('results', $results);

		// Custom fields
		$custom_fields = DAO_CustomField::getBySource(ChCustomFieldSource_Task::ID);
		$tpl->assign('custom_fields', $custom_fields);
		
		$tpl->assign('view_fields', $this->getColumns());
		
		switch($this->renderTemplate) {
			case 'contextlinks_chooser':
				$tpl->display('file:' . APP_PATH . '/features/cerberusweb.core/templates/tasks/view_contextlinks_chooser.tpl');
				break;
			default:
				$tpl->display('file:' . APP_PATH . '/features/cerberusweb.core/templates/tasks/view.tpl');
				break;
		}
		
	}

	function renderCriteria($field) {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl_path = APP_PATH . '/features/cerberusweb.core/templates/';
		$tpl->assign('id', $this->id);
		
		switch($field) {
			case SearchFields_Task::TITLE:
				$tpl->display('file:' . APP_PATH . '/features/cerberusweb.core/templates/internal/views/criteria/__string.tpl');
				break;
				
			case SearchFields_Task::IS_COMPLETED:
				$tpl->display('file:' . APP_PATH . '/features/cerberusweb.core/templates/internal/views/criteria/__bool.tpl');
				break;
				
			case SearchFields_Task::UPDATED_DATE:
			case SearchFields_Task::DUE_DATE:
			case SearchFields_Task::COMPLETED_DATE:
				$tpl->display('file:' . APP_PATH . '/features/cerberusweb.core/templates/internal/views/criteria/__date.tpl');
				break;
				
			case SearchFields_Task::WORKER_ID:
				$workers = DAO_Worker::getAll();
				$tpl->assign('workers', $workers);
				
				$tpl->display('file:' . APP_PATH . '/features/cerberusweb.core/templates/internal/views/criteria/__worker.tpl');
				break;

			default:
				// Custom Fields
				if('cf_' == substr($field,0,3)) {
					$this->_renderCriteriaCustomField($tpl, substr($field,3));
				} else {
					echo ' ';
				}
				break;
		}
	}

	function renderCriteriaParam($param) {
		$field = $param->field;
		$translate = DevblocksPlatform::getTranslationService();
		$values = !is_array($param->value) ? array($param->value) : $param->value;

		switch($field) {
			case SearchFields_Task::WORKER_ID:
				$workers = DAO_Worker::getAll();
				$strings = array();

				foreach($values as $val) {
					if(empty($val))
						$strings[] = "Nobody";
					elseif(!isset($workers[$val]))
						continue;
					else
						$strings[] = $workers[$val]->getName();
				}
				echo implode(", ", $strings);
				break;
				
			default:
				parent::renderCriteriaParam($param);
				break;
		}
	}

	static function getFields() {
		return SearchFields_Task::getFields();
	}

	static function getSearchFields() {
		$fields = self::getFields();
		unset($fields[SearchFields_Task::ID]);
		return $fields;
	}

	static function getColumns() {
		$fields = self::getFields();
		unset($fields[SearchFields_Task::ID]);
		return $fields;
	}

	function doResetCriteria() {
		parent::doResetCriteria();
		
		$this->params = array(
			SearchFields_Task::IS_COMPLETED => new DevblocksSearchCriteria(SearchFields_Task::IS_COMPLETED,'=',0)
		);
	}
	
	function doSetCriteria($field, $oper, $value) {
		$criteria = null;

		switch($field) {
			case SearchFields_Task::TITLE:
				// force wildcards if none used on a LIKE
				if(($oper == DevblocksSearchCriteria::OPER_LIKE || $oper == DevblocksSearchCriteria::OPER_NOT_LIKE)
				&& false === (strpos($value,'*'))) {
					$value = '*'.$value.'*';
				}
				$criteria = new DevblocksSearchCriteria($field, $oper, $value);
				break;
				
			case SearchFields_Task::UPDATED_DATE:
			case SearchFields_Task::COMPLETED_DATE:
			case SearchFields_Task::DUE_DATE:
				@$from = DevblocksPlatform::importGPC($_REQUEST['from'],'string','');
				@$to = DevblocksPlatform::importGPC($_REQUEST['to'],'string','');

				if(empty($from)) $from = 0;
				if(empty($to)) $to = 'today';

				$criteria = new DevblocksSearchCriteria($field,$oper,array($from,$to));
				break;

			case SearchFields_Task::IS_COMPLETED:
				@$bool = DevblocksPlatform::importGPC($_REQUEST['bool'],'integer',1);
				$criteria = new DevblocksSearchCriteria($field,$oper,$bool);
				break;
				
			case SearchFields_Task::WORKER_ID:
				@$worker_id = DevblocksPlatform::importGPC($_REQUEST['worker_id'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,$oper,$worker_id);
				break;
				
			default:
				// Custom Fields
				if(substr($field,0,3)=='cf_') {
					$criteria = $this->_doSetCriteriaCustomField($field, substr($field,3));
				}
				break;
		}

		if(!empty($criteria)) {
			$this->params[$field] = $criteria;
			$this->renderPage = 0;
		}
	}
	
	function doBulkUpdate($filter, $do, $ids=array()) {
		@set_time_limit(600); // [TODO] Temp!
	  
		$change_fields = array();
		$custom_fields = array();

		// Make sure we have actions
		if(empty($do))
			return;

		// Make sure we have checked items if we want a checked list
		if(0 == strcasecmp($filter,"checks") && empty($ids))
			return;
			
		if(is_array($do))
		foreach($do as $k => $v) {
			switch($k) {
				case 'due':
					@$date = strtotime($v);
					$change_fields[DAO_Task::DUE_DATE] = intval($date);
					break;
				case 'status':
					if(1==intval($v)) { // completed
						$change_fields[DAO_Task::IS_COMPLETED] = 1;
						$change_fields[DAO_Task::COMPLETED_DATE] = time();
					} else { // active
						$change_fields[DAO_Task::IS_COMPLETED] = 0;
						$change_fields[DAO_Task::COMPLETED_DATE] = 0;
					}
					break;
				case 'worker_id':
					$change_fields[DAO_Task::WORKER_ID] = intval($v);
					break;
				default:
					// Custom fields
					if(substr($k,0,3)=="cf_") {
						$custom_fields[substr($k,3)] = $v;
					}
			}
		}
		
		$pg = 0;

		if(empty($ids))
		do {
			list($objects,$null) = DAO_Task::search(
				array(),
				$this->params,
				100,
				$pg++,
				SearchFields_Task::ID,
				true,
				false
			);
			 
			$ids = array_merge($ids, array_keys($objects));
			 
		} while(!empty($objects));

		$batch_total = count($ids);
		for($x=0;$x<=$batch_total;$x+=100) {
			$batch_ids = array_slice($ids,$x,100);
			DAO_Task::update($batch_ids, $change_fields);
			
			// Custom Fields
			self::_doBulkSetCustomFields(ChCustomFieldSource_Task::ID, $custom_fields, $batch_ids);
			
			unset($batch_ids);
		}

		unset($ids);
	}	
};

class Context_Task extends Extension_DevblocksContext {
    function __construct($manifest) {
        parent::__construct($manifest);
    }

	function getContext($task, &$token_labels, &$token_values, $prefix=null) {
		if(is_null($prefix))
			$prefix = 'Task:';
		
		$translate = DevblocksPlatform::getTranslationService();
		$fields = DAO_CustomField::getBySource(ChCustomFieldSource_Task::ID);

		// Polymorph
		if(is_numeric($task)) {
			$task = DAO_Task::get($task);
		} elseif($task instanceof Model_Task) {
			// It's what we want already.
		} else {
			$task = null;
		}
		
		// Token labels
		$token_labels = array(
			'completed|date' => $prefix.$translate->_('task.completed_date'),
			'due|date' => $prefix.$translate->_('task.due_date'),
			'id' => $prefix.$translate->_('common.id'),
			'is_completed' => $prefix.$translate->_('task.is_completed'),
			'title' => $prefix.$translate->_('task.title'),
			'updated|date' => $prefix.$translate->_('task.updated_date'),
		);
		
		if(is_array($fields))
		foreach($fields as $cf_id => $field) {
			$token_labels['custom_'.$cf_id] = $prefix.$field->name;
		}

		// Token values
		$token_values = array();
		
		if($task) {
			$token_values['completed'] = $task->completed_date;
			$token_values['due'] = $task->due_date;
			$token_values['id'] = $task->id;
			$token_values['is_completed'] = $task->is_completed;
			$token_values['title'] = $task->title;
			$token_values['updated'] = $task->updated_date;
			
			$token_values['custom'] = array();
			
			$field_values = array_shift(DAO_CustomFieldValue::getValuesBySourceIds(ChCustomFieldSource_Task::ID, $task->id));
			if(is_array($field_values) && !empty($field_values)) {
				foreach($field_values as $cf_id => $cf_val) {
					if(!isset($fields[$cf_id]))
						continue;
					
					// The literal value
					if(null != $task)
						$token_values['custom'][$cf_id] = $cf_val;
					
					// Stringify
					if(is_array($cf_val))
						$cf_val = implode(', ', $cf_val);
						
					if(is_string($cf_val)) {
						if(null != $task)
							$token_values['custom_'.$cf_id] = $cf_val;
					}
				}
			}
		}

		// Assignee
		@$assignee_id = $task->worker_id;
		$merge_token_labels = array();
		$merge_token_values = array();
		CerberusContexts::getContext(CerberusContexts::CONTEXT_WORKER, $assignee_id, $merge_token_labels, $merge_token_values, '', true);

		CerberusContexts::merge(
			'assignee_',
			'Assignee:',
			$merge_token_labels,
			$merge_token_values,
			$token_labels,
			$token_values
		);			
		
		return true;
	}

	function renderChooserPanel($from_context, $from_context_id, $to_context, $return_uri) {
		$active_worker = CerberusApplication::getActiveWorker();
		
		$tpl = DevblocksPlatform::getTemplateService();
		$path = dirname(dirname(dirname(__FILE__))) . '/templates/';
		$tpl->assign('path', $path);
		
		$tpl->assign('context', $this);
		$tpl->assign('from_context', $from_context);
		$tpl->assign('from_context_id', $from_context_id);
		$tpl->assign('to_context', $to_context);
		$tpl->assign('context_extension', $this);
		$tpl->assign('return_uri', $return_uri);
		
		$links = DAO_ContextLink::getLinks($from_context, $from_context_id);
		$ids = array();
		
		if(is_array($links))
		foreach($links as $link) {
			if($link->context !== $to_context)
				continue;
			$ids[] = $link->context_id;
		}
		
		if(!empty($ids)) {
			$links = array();
			$link_ids = DAO_Task::getWhere(sprintf("%s IN (%s)",
				DAO_Task::ID,
				implode(',', $ids)
			));
			
			if(is_array($link_ids))
			foreach($link_ids as $link_id => $link) {
				$links[$link_id] = sprintf("%s", $link->title);
			}
			
			$tpl->assign('links', $links);
		}
		
		// View
		
		$view_id = 'contextlink_'.str_replace('.','_',$this->id);
		$defaults = new C4_AbstractViewModel();
		$defaults->id = $view_id; 
		$defaults->class_name = 'View_Task';
		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		$view->name = 'Tasks';
		$view->view_columns = array(
			SearchFields_Task::UPDATED_DATE,
			SearchFields_Task::DUE_DATE,
			SearchFields_Task::WORKER_ID,
		);
		$view->params = array(
			SearchFields_Task::IS_COMPLETED => new DevblocksSearchCriteria(SearchFields_Task::IS_COMPLETED,'=',0),
			SearchFields_Task::WORKER_ID => new DevblocksSearchCriteria(SearchFields_Task::WORKER_ID,'=',$active_worker->id),
		);
		$view->renderSortBy = SearchFields_Task::UPDATED_DATE;
		$view->renderSortAsc = false;
		$view->renderLimit = 10;
		$view->renderTemplate = 'contextlinks_chooser';
		C4_AbstractViewLoader::setView($view_id, $view);
		$tpl->assign('view', $view);

		$tpl->assign('view_fields', View_Task::getFields());
		$tpl->assign('view_searchable_fields', View_Task::getSearchFields());
		
		// Template
		
		$tpl->display('file:'.$path.'context_links/choosers/__generic.tpl');
	}	
	
	function saveChooserPanel($from_context, $from_context_id, $to_context, $to_context_data) {
		if(is_array($to_context_data))
		foreach($to_context_data as $to_context_item) {
			if(!empty($to_context) && null != ($task = DAO_Task::get($to_context_item))) {
				DAO_ContextLink::setLink($from_context, $from_context_id, $to_context, $task->id);
			}
		}
		
		return TRUE;
	}

	
	function getView($ids) {
		$view_id = str_replace('.','_',$this->id);
		
		$defaults = new C4_AbstractViewModel();
		$defaults->id = $view_id; 
		$defaults->class_name = 'View_Task';
		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		$view->name = 'Tasks';
		$view->params = array(
			SearchFields_Task::ID => new DevblocksSearchCriteria(SearchFields_Task::ID,'in',$ids),
		);
		C4_AbstractViewLoader::setView($view_id, $view);
		return $view;
	}
};
