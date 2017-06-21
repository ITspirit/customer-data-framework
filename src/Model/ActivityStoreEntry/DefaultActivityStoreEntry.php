<?php
/**
 * Created by PhpStorm.
 * User: mmoser
 * Date: 10.10.2016
 * Time: 11:22
 */

namespace CustomerManagementFrameworkBundle\Model\ActivityStoreEntry;

use Carbon\Carbon;
use CustomerManagementFrameworkBundle\Factory;
use CustomerManagementFrameworkBundle\Helper\Json;
use CustomerManagementFrameworkBundle\Model\ActivityInterface;
use CustomerManagementFrameworkBundle\Model\ActivityStoreEntry\ActivityStoreEntryInterface;
use CustomerManagementFrameworkBundle\Model\CustomerInterface;

class DefaultActivityStoreEntry implements ActivityStoreEntryInterface {

    /**
     * @var array $data
     */
    private $data;


    /**
     * @var int;
     */
    private $id;

    /**
     * @var CustomerInterface
     */
    private $customer;

    /**
     * @var int
     */
    private $customerId;

    /**
     * @var int
     */
    private $activityDate;

    /**
     * @var string
     */
    private $type;

    /**
     * @var ActivityInterface
     */
    private $relatedItem;

    /**
     * @var int
     */
    private $creationDate;

    /**
     * @var int
     */
    private $modificationDate;

    /**
     * @var $md5
     */
    private $md5;

    /**
     * @var int|null
     */
    private $o_id;

    /**
     * @var int|null
     */
    private $a_id;

    /**
     * @var string
     */
    private $implementationClass;


    /**
     * @var $attributes
     */
    private $attributes;

    public function setData($data) {

        $this->data = $data;

        $this->setId($data['id']);
        $this->setActivityDate($data['activityDate']);
        $this->setType($data['type']);
        $this->setImplementationClass($data['implementationClass']);
        if(isset($data['attributes'])) {
            $this->setAttributes(is_array($data['attributes']) ? $data['attributes'] : json_decode(Json::cleanUpJson($data['attributes']), true));
        }
        $this->setMd5($data['md5']);
        $this->setCreationDate($data['creationDate']);
        $this->setModificationDate($data['modificationDate']);
        $this->o_id = $data['o_id'];
        $this->a_id = $data['a_id'];
        $this->customerId = intval($data['customerId']);
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param int $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return CustomerInterface
     */
    public function getCustomer()
    {
        if(empty($this->customer) && $this->customerId) {
            $this->customer = \Pimcore::getContainer()->get('cmf.customer_provider')->getById($this->customerId);
        }

        return $this->customer;
    }

    /**
     * @return int
     */
    public function getCustomerId()
    {
        return $this->customerId;
    }

    /**
     * @param CustomerInterface $customer
     */
    public function setCustomer(CustomerInterface $customer)
    {
        $this->customer = $customer;
        $this->customerId = $customer->getId();
    }

    /**
     * @return Carbon
     */
    public function getActivityDate()
    {
        return $this->activityDate;
    }

    /**
     * @param int $activityDate
     */
    public function setActivityDate($activityDate)
    {
        $this->activityDate = Carbon::createFromTimestamp($activityDate);
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * @return ActivityInterface
     */
    public function getRelatedItem()
    {
        if(empty($this->relatedItem)) {
            $implementationClass = self::getImplementationClass();
            $implementationClass = \Pimcore::getDiContainer()->has($implementationClass) ? \Pimcore::getDiContainer()->get($implementationClass) : $implementationClass;
            $attributes = $this->getAttributes();
            $attributes['activityDate'] = $this->getActivityDate();
            $attributes['o_id'] = $this->o_id ? : $attributes['o_id'];
            $attributes['a_id'] = $this->a_id ? : $attributes['a_id'];
            $this->relatedItem = \Pimcore::getDiContainer()->call([$implementationClass , 'cmfCreate'], [$attributes]);
        }

        return $this->relatedItem;
    }

    /**
     * @param ActivityInterface $relatedItem
     */
    public function setRelatedItem(ActivityInterface $relatedItem)
    {
        $this->relatedItem = $relatedItem;
    }

    /**
     * @return int
     */
    public function getCreationDate()
    {
        return $this->creationDate;
    }

    /**
     * @param int $creationDate
     */
    public function setCreationDate($creationDate)
    {
        $this->creationDate = $creationDate;
    }

    /**
     * @return int
     */
    public function getModificationDate()
    {
        return $this->modificationDate;
    }

    /**
     * @param int $modificationDate
     */
    public function setModificationDate($modificationDate)
    {
        $this->modificationDate = $modificationDate;
    }

    /**
     * @return string
     */
    public function getMd5()
    {
        return $this->md5;
    }

    /**
     * @param string $md5
     */
    public function setMd5($md5)
    {
        $this->md5 = $md5;
    }

    /**
     * @return string
     */
    public function getImplementationClass()
    {
        return $this->implementationClass;
    }

    /**
     * @param string $implementationClass
     */
    public function setImplementationClass($implementationClass)
    {
        $this->implementationClass = $implementationClass;
    }

    /**
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * @param array $attributes
     */
    public function setAttributes(array $attributes)
    {
        $this->attributes = $attributes;
    }

    public function getData()
    {
        $data = $this->data;
        $data['id'] = $this->getId();
        $data['customerId'] = $this->customerId;
        $data['activityDate'] = $this->getActivityDate()->getTimestamp();
        $data['type'] = $this->getType();
        $data['implementationClass'] = $this->getImplementationClass();
        $data['attributes'] = $this->getAttributes();
        $data['md5'] = $this->getMd5();
        $data['creationDate'] = $this->getCreationDate();
        $data['modificationDate'] = $this->getModificationDate();
        $data['o_id'] = $this->o_id;
        $data['a_id'] = $this->a_id;

        return $data;
    }

    public function save($updateAttributes = false)
    {
        $relatedItem = $this->getRelatedItem();
        $relatedItem->setCustomer($this->getCustomer());
        \Pimcore::getContainer()->get('cmf.activity_store')->updateActivityStoreEntry($this, $updateAttributes);
    }

}