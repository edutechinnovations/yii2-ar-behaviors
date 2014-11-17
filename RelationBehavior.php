<?php

namespace mdm\behaviors\ar;

use yii\db\ActiveRecord;

/**
 * RelationBehavior
 *
 * @property ActiveRecord $owner
 * 
 * @author Misbahul D Munir <misbahuldmunir@gmail.com>
 * @since 1.0
 */
class RelationBehavior extends \yii\base\Behavior
{
    /**
     * @var array scenario for relation 
     */
    public $relatedScenarios = [];

    /**
     * @var \Closure callback execute before related validate.
     * 
     * ```php
     * function($model,$index,$relationName){
     * 
     * }
     * ```
     */
    public $beforeRValidate;

    /**
     * @var \Closure Execute before relation save
     * When return false, save will be canceled
     * @see [[$beforeRValidate]]
     * If function return `false`, save will be canceled
     */
    public $beforeRSave;

    /**
     * @var \Closure Execute after relation save
     * @see [[$beforeRValidate]]
     */
    public $afterRSave;

    /**
     * @var boolean If true clear related error
     */
    public $clearError = true;

    /**
     * @var boolean 
     */
    public $deleteUnsaved = true;

    /**
     * @var array 
     */
    private $_old_relations = [];

    /**
     * @var array 
     */
    private $_process_relation = [];

    /**
     * @var array 
     */
    private $_relatedErrors = [];

    /**
     * @inheritdoc
     */
    public function events()
    {
        return[
            ActiveRecord::EVENT_AFTER_VALIDATE => 'afterValidate',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
        ];
    }

    /**
     * @inheritdoc
     */
    public function __set($name, $value)
    {
        if ($this->setRelated($name, $value) === false) {
            parent::__set($name, $value);
        }
    }

    /**
     * @inheritdoc
     */
    public function canSetProperty($name, $checkVars = true)
    {
        return $this->owner->getRelation($name, false) !== null || parent::canSetProperty($name, $checkVars);
    }

    /**
     * Populate relation
     * @param string $name
     * @param array $values
     * @return boolean
     */
    public function setRelated($name, $values)
    {
        $relation = $this->owner->getRelation($name, false);
        if ($relation === null) {
            return false;
        }
        $class = $relation->modelClass;
        $multiple = $relation->multiple;
        $link = $relation->link;
        $uniqueKeys = array_flip($class::primaryKey());
        foreach (array_keys($link) as $from) {
            unset($uniqueKeys[$from]);
        }
        $uniqueKeys = array_keys($uniqueKeys);

        $children = $this->owner->$name;
        if ($multiple) {
            $newChildren = [];
            foreach ($values as $index => $value) {
                // get from current relation
                // if has child with same primary key, use this
                /* @var $newChild \yii\db\ActiveRecord */
                $newChild = null;
                if (empty($relation->indexBy)) {
                    foreach ($children as $i => $child) {
                        if ($this->checkEqual($child, $value, $uniqueKeys)) {
                            $newChild = $child;
                            unset($children[$i]);
                            break;
                        }
                    }
                } elseif (isset($children[$index])) {
                    $newChild = $children[$index];
                    unset($children[$index]);
                }
                if ($newChild === null) {
                    $newChild = new $class;
                }
                if (isset($this->relatedScenarios[$name])) {
                    $newChild->scenario = $this->relatedScenarios[$name];
                }
                $newChild->load($value, '');
                foreach ($link as $from => $to) {
                    $newChild->$from = $this->owner->$to;
                }
                $newChildren[$index] = $newChild;
            }
            $this->_old_relations[$name] = $children;
            $this->owner->populateRelation($name, $newChildren);
            $this->_process_relation[$name] = true;
        } else {
            if ($children === null) {
                $children = new $class;
            }
            if (isset($this->relatedScenarios[$name])) {
                $children->scenario = $this->relatedScenarios[$name];
            }
            $children->load($values, '');
            foreach ($link as $from => $to) {
                $children->$from = $this->owner->$to;
            }
            $this->owner->populateRelation($name, $children);
            $this->_process_relation[$name] = true;
        }
        return true;
    }

    /**
     * Handler for event afterValidate
     */
    public function afterValidate()
    {
        /* @var $child \yii\db\ActiveRecord */
        foreach ($this->_process_relation as $name => $process) {
            if (!$process) {
                continue;
            }
            if ($this->clearError) {
                $this->_relatedErrors[$name] = [];
            }
            $error = false;
            $relation = $this->owner->getRelation($name);
            $children = $this->owner->$name;
            if ($relation->multiple) {
                foreach ($children as $index => $child) {
                    if (isset($this->beforeRValidate)) {
                        call_user_func($this->beforeRValidate, $child, $index, $name);
                    }
                    if (!$child->validate()) {
                        $this->_relatedErrors[$name][$index] = $child->getFirstErrors();
                        $error = true;
                    }
                }
            } else {
                if (isset($this->beforeRValidate)) {
                    call_user_func($this->beforeRValidate, $children, null, $name);
                }
                if (!$children->validate()) {
                    $this->_relatedErrors[$name] = $child->getFirstErrors();
                    $error = true;
                }
            }
            if ($error) {
                $this->owner->addError($name, 'Related error');
            }
        }
    }

    /**
     * Handler for event afterSave
     */
    public function afterSave()
    {
        foreach ($this->_process_relation as $name => $process) {
            if (!$process) {
                continue;
            }
            // delete old related
            /* @var $child \yii\db\ActiveRecord */
            if (isset($this->_old_relations[$name])) {
                foreach ($this->_old_relations[$name] as $child) {
                    $child->delete();
                }
                unset($this->_old_relations[$name]);
            }
            // save new relation
            $relation = $this->owner->getRelation($name);
            $link = $relation->link;
            $children = $this->owner->$name;
            if ($relation->multiple) {
                foreach ($children as $index => $child) {
                    foreach ($link as $from => $to) {
                        $child->$from = $this->owner->$to;
                    }
                    if ($this->beforeRSave === null || call_user_func($this->beforeRSave, $child, $index, $name) !== false) {
                        $child->save(false);
                        if (isset($this->afterRSave)) {
                            call_user_func($this->afterRSave, $child, $index, $name);
                        }
                    } elseif ($this->deleteUnsaved && !$child->getIsNewRecord()) {
                        $child->delete();
                    }
                }
            } else {
                /* @var $children \yii\db\ActiveRecord */
                foreach ($link as $from => $to) {
                    $children->$from = $this->owner->$to;
                }
                if ($this->beforeRSave === null || call_user_func($this->beforeRSave, $children, null, $name) !== false) {
                    $children->save(false);
                    if (isset($this->afterRSave)) {
                        call_user_func($this->afterRSave, $children, null, $name);
                    } elseif ($this->deleteUnsaved && !$children->getIsNewRecord()) {
                        $child->delete();
                    }
                }
            }
            unset($this->_process_relation[$name]);
        }
    }

    /**
     * Check if relation has error.
     * @param  string  $relationName
     * @return boolean
     */
    public function hasRelatedErrors($relationName = null)
    {
        if ($relationName === null) {
            foreach ($this->_relatedErrors as $errors) {
                if (!empty($errors)) {
                    return true;
                }
            }
            return false;
        } else {
            return !empty($this->_relatedErrors[$relationName]);
        }
    }

    /**
     * Get related error(s)
     * @param string|null $relationName
     * @return array
     */
    public function getRelatedErrors($relationName = null)
    {
        if ($relationName === null) {
            return $this->_relatedErrors;
        } else {
            return isset($this->_relatedErrors[$relationName]) ? $this->_relatedErrors[$relationName] : [];
        }
    }

    /**
     * Check is boot of model is equal
     * @param \yii\db\ActiveRecord|array $model1
     * @param \yii\db\ActiveRecord|array $model2
     * @param array $keys
     * @return boolean
     */
    protected function checkEqual($model1, $model2, $keys)
    {
        foreach ($keys as $key) {
            if ($model1[$key] != $model2[$key]) {
                return false;
            }
        }
        return true;
    }
}