<?php

namespace mdm\behaviors\ar;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * RelatedBehavior
 * Use to save relation 
 *
 * @property \yii\db\ActiveRecord $owner
 * @property array $relatedErrors
 * 
 * @author Misbahul D Munir <misbahuldmunir@gmail.com>
 * @since 1.0
 */
class RelatedBehavior extends \yii\base\Behavior
{
    /**
     * @var array 
     */
    public $extraData;

    /**
     * @var Closure Execute before relation validate
     * 
     * ```php
     * function($model, $index){
     *     // for hasOne relation, value or $index is null
     * }
     * ```
     */
    public $beforeRValidate;

    /**
     * @var Closure Execute before relation save
     * @see [[$beforeRValidate]]
     * If function return `false`, save will be canceled
     */
    public $beforeRSave;

    /**
     * @var Closure Execute after relation save
     * @see [[$beforeRValidate]]
     */
    public $afterRSave;

    /**
     * @var boolean If true clear related error
     */
    public $clearError = true;

    /**
     * @var array 
     */
    protected $_relatedErrors = [];

    /**
     * Save related model(s) provided by `$data`.
     * @param  string $relationName
     * @param  array $data
     * @param  boolean $saved if false, related model only be validated without saved.
     * @param  boolean|string $scope
     * @param  string   $scenario
     * @return boolean true if success
     */
    public function saveRelated($relationName, $data, $saved = true, $scope = null, $scenario = null)
    {
        return $this->doSaveRelated($relationName, $data, $saved, $scope, $scenario);
    }

    /**
     * @see [[saveRelated()]]
     */
    protected function doSaveRelated($relationName, $data, $save, $scope, $scenario)
    {
        $model = $this->owner;
        $relation = $model->getRelation($relationName);

        // link of relation
        $links = [];
        foreach ($relation->link as $from => $to) {
            $links[$from] = $model[$to];
        }

        /* @var $class \yii\db\ActiveRecord */
        $class = $relation->modelClass;
        $multiple = $relation->multiple;
        if ($multiple) {
            $children = $relation->all();
        } else {
            $children = $relation->one();
        }
        $pks = $class::primaryKey();

        if ($scope === null) {
            $postDetails = ArrayHelper::getValue($data, (new $class)->formName(), []);
        } elseif ($scope === false) {
            $postDetails = $data;
        } else {
            $postDetails = ArrayHelper::getValue($data, $scope, []);
        }

        if ($this->clearError) {
            $this->_relatedErrors[$relationName] = [];
        }
        /* @var $detail \yii\db\ActiveRecord */
        $error = false;
        if ($multiple) {
            $population = [];
            foreach ($postDetails as $index => $dataDetail) {
                if ($this->extraData !== null) {
                    $dataDetail = array_merge($this->extraData, $dataDetail);
                }
                $dataDetail = array_merge($dataDetail, $links);

                // set primary key of detail
                $detailPks = [];
                if (count($pks) === 1) {
                    $detailPks = isset($dataDetail[$pks[0]]) ? $dataDetail[$pks[0]] : null;
                } else {
                    foreach ($pks as $pkName) {
                        $detailPks[$pkName] = isset($dataDetail[$pkName]) ? $dataDetail[$pkName] : null;
                    }
                }

                $detail = null;
                // get from current relation
                // if has child with same primary key, use this
                if (empty($relation->indexBy)) {
                    foreach ($children as $i => $child) {
                        if ($child->getPrimaryKey() === $detailPks) {
                            $detail = $child;
                            unset($children[$i]);
                            break;
                        }
                    }
                } elseif (isset($children[$index])) {
                    $detail = $children[$index];
                    unset($children[$index]);
                }
                if ($detail === null) {
                    $detail = new $class;
                }
                if ($scenario !== null) {
                    $detail->setScenario($scenario);
                }
                $detail->load($dataDetail, '');

                if (isset($this->beforeRValidate)) {
                    call_user_func($this->beforeRValidate, $detail, $index);
                }
                if (!$detail->validate()) {
                    $this->_relatedErrors[$relationName][$index] = $detail->getFirstErrors();
                    $error = true;
                }
                $population[$index] = $detail;
            }
        } else {
            /* @var $population \yii\db\ActiveRecord */
            $population = $children === null ? new $class : $children;
            $dataDetail = $postDetails;
            if (isset($this->extraData)) {
                $dataDetail = array_merge($this->extraData, $dataDetail);
            }
            $dataDetail = array_merge($dataDetail, $links);
            if ($scenario !== null) {
                $population->setScenario($scenario);
            }
            $population->load($dataDetail, '');
            if (isset($this->beforeRValidate)) {
                call_user_func($this->beforeRValidate, $population, null);
            }
            if (!$population->validate()) {
                $this->_relatedErrors[$relationName] = $population->getFirstErrors();
                $error = true;
            }
        }

        if (!$error && $save) {
            if ($multiple) {
                // delete current children before inserting new
                $linkFilter = [];
                $columns = array_flip($pks);
                foreach ($relation->link as $from => $to) {
                    $linkFilter[$from] = $model->$to;
                    // reduce primary key that linked to parent
                    if (isset($columns[$from])) {
                        unset($columns[$from]);
                    }
                }
                $values = [];
                if (!empty($columns)) {
                    $columns = array_keys($columns);
                    foreach ($children as $child) {
                        $value = [];
                        foreach ($columns as $column) {
                            $value[$column] = $child[$column];
                        }
                        $values[] = $value;
                    }
                    if (!empty($values)) {
                        foreach ($class::find()->where(['and', $linkFilter, ['in', $columns, $values]])->all() as $related) {
                            $related->delete();
                        }
                    }
                } else {
                    foreach ($class::find()->where($linkFilter)->all() as $related) {
                        $related->delete();
                    }
                }
                foreach ($population as $index => $detail) {
                    if (!isset($this->beforeRSave) || call_user_func($this->beforeRSave, $detail, $index) !== false) {
                        if (!$detail->save(false)) {
                            $error = true;
                            break;
                        }
                        if (isset($this->afterRSave)) {
                            call_user_func($this->afterRSave, $detail, $index);
                        }
                    }
                }
            } else {
                if (!isset($this->beforeRSave) || call_user_func($this->beforeRSave, $population, null) !== false) {
                    if ($population->save(false)) {
                        if (isset($this->beforeRSave)) {
                            call_user_func($this->beforeRSave, $population, null);
                        }
                    } else {
                        $error = true;
                    }
                }
            }
        }

        $model->populateRelation($relationName, $population);

        return !$error && $save;
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
}