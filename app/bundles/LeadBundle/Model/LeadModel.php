<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\LeadBundle\Model;

use Mautic\CoreBundle\Model\FormModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\SocialBundle\Helper\NetworkIntegrationHelper;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Class LeadModel
 * {@inheritdoc}
 * @package Mautic\CoreBundle\Model\FormModel
 */
class LeadModel extends FormModel
{

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getRepository()
    {
        return $this->em->getRepository('MauticLeadBundle:Lead');
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getPermissionBase()
    {
        return 'lead:leads';
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getNameGetter()
    {
        return "getPrimaryIdentifier";
    }

    /**
     * {@inheritdoc}
     *
     * @param array $args [start, limit, filter, orderBy, orderByDir]
     * @return mixed
     */
    public function getEntities(array $args = array())
    {
        //set the point trigger model in order to get the color code for the lead
        $repo = $this->getRepository();
        $repo->setTriggerModel($this->factory->getModel('point.trigger'));

        return parent::getEntities($args);
    }

    /**
     * {@inheritdoc}
     *
     * @param      $entity
     * @param      $formFactory
     * @param null $action
     * @param array $options
     * @return mixed
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    public function createForm($entity, $formFactory, $action = null, $options = array())
    {
        if (!$entity instanceof Lead) {
            throw new MethodNotAllowedHttpException(array('Lead'), 'Entity must be of class Lead()');
        }
        if (!empty($action))  {
            $options['action'] = $action;
        }
        return $formFactory->create('lead', $entity, $options);
    }

    /**
     * Get a specific entity or generate a new one if id is empty
     *
     * @param $id
     * @return null|object
     */
    public function getEntity($id = null)
    {
        if ($id === null) {
            return new Lead();
        }

        $entity = parent::getEntity($id);

        return $entity;
    }

    /**
     * {@inheritdoc}
     *
     * @param $action
     * @param $event
     * @param $entity
     * @param $isNew
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, $event = false)
    {
        if (!$entity instanceof Lead) {
            throw new MethodNotAllowedHttpException(array('Lead'), 'Entity must be of class Lead()');
        }

        switch ($action) {
            case "pre_save":
                $name = LeadEvents::LEAD_PRE_SAVE;
                break;
            case "post_save":
                $name = LeadEvents::LEAD_POST_SAVE;
                break;
            case "pre_delete":
                $name = LeadEvents::LEAD_PRE_DELETE;
                break;
            case "post_delete":
                $name = LeadEvents::LEAD_POST_DELETE;
                break;
            default:
                return false;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                $event = new LeadEvent($entity, $isNew);
                $event->setEntityManager($this->em);
            }
            $this->dispatcher->dispatch($name, $event);

            return $event;
        } else {
            return false;
        }
    }

    /**
     * Populates custom field values for updating the lead. Also retrieves social media data
     *
     * @param Lead  $lead
     * @param array $data
     * @param $overwriteWithBlank
     * @return array
     */
    public function setFieldValues(Lead &$lead, array $data, $overwriteWithBlank = true)
    {
        //@todo - add a catch to NOT do social gleaning if a lead is created via a form, etc as we do not want the user to experience the wait
        //generate the social cache
        list($socialCache, $socialFeatureSettings) = NetworkIntegrationHelper::getUserProfiles($this->factory, $lead, $data, true, null, false, true);

        $isNew = ($lead->getId()) ? false : true;

        //set the social cache while we have it
        $lead->setSocialCache($socialCache);

        //save the field values
        if (!$isNew) {
            $fieldValues = $lead->getFields();
        } else {
            static $fields;
            if (empty($fields)) {
                $fields = $this->factory->getModel('lead.field')->getEntities(array(
                    'filter'         => array('isPublished' => true),
                    'hydration_mode' => 'HYDRATE_ARRAY'
                ));
                $fields = $this->organizeFieldsByGroup($fields);
            }
            $fieldValues = $fields;
        }

        //update existing values
        foreach ($fieldValues as $group => &$groupFields) {
            foreach ($groupFields as $alias => &$field) {
                if (!isset($field['value'])) {
                    $field['value'] = null;
                }

                $curValue = $field['value'];
                $newValue = (isset($data[$alias])) ? $data[$alias] : "";
                if ($curValue !== $newValue && (!empty($newValue) || (empty($newValue) && $overwriteWithBlank))) {
                    $field['value'] = $newValue;
                    $lead->addUpdatedField($alias, $newValue);
                }

                //if empty, check for social media data to plug the hole
                if (empty($newValue) && !empty($socialCache)) {
                    foreach ($socialCache as $service => $details) {
                        //check to see if a field has been assigned

                        if (!empty($socialFeatureSettings[$service]['leadFields']) &&
                            in_array($field['id'], $socialFeatureSettings[$service]['leadFields'])
                        ) {

                            //check to see if the data is available
                            $key = array_search($field['id'], $socialFeatureSettings[$service]['leadFields']);
                            if (isset($details['profile'][$key])) {
                                //Found!!
                                $field['value'] = $details['profile'][$key];
                                $lead->addUpdatedField($alias, $details['profile'][$key]);
                                break;
                            }
                        }
                    }
                }
            }
        }

        $lead->setFields($fieldValues);
    }

    /**
     * Disassociates a user from leads
     *
     * @param $userId
     */
    public function disassociateOwner($userId)
    {
        $leads = $this->getRepository()->findByOwner($userId);
        foreach ($leads as $lead) {
            $lead->setOwner(null);
            $this->saveEntity($lead);
        }
    }

    /**
     * Get list of entities for autopopulate fields
     *
     * @param $type
     * @param $filter
     * @param $limit
     * @return array
     */
    public function getLookupResults($type, $filter = '', $limit = 10)
    {
        $results = array();
        switch ($type) {
            case 'user':
                $results = $this->em->getRepository('MauticUserBundle:User')->getUserList($filter, $limit, 0, array('lead' => 'leads'));
                break;
        }

        return $results;
    }

    /**
     * Obtain an array of users for api lead edits
     *
     * @return mixed
     */
    public function getOwnerList()
    {
        $results = $this->em->getRepository('MauticUserBundle:User')->getUserList('', 0);
        return $results;
    }

    /**
     * Obtains a list of leads based off IP
     *
     * @param $ip
     *
     * @return mixed
     */
    public function getLeadsByIp($ip)
    {
        return $this->getRepository()->getLeadsByIp($ip);
    }

    /**
     * Reorganizes a field list to be keyed by field's group then alias
     *
     * @param $fields
     * @return array
     */
    public function organizeFieldsByGroup($fields)
    {
        $array = array();
        foreach ($fields as $alias => $field) {
            if ($field instanceof LeadField) {
                if ($field->isPublished()) {
                    $group                          = $field->getGroup();
                    $array[$group][$alias]['id']    = $field->getId();
                    $array[$group][$alias]['group'] = $group;
                    $array[$group][$alias]['label'] = $field->getLabel();
                    $array[$group][$alias]['alias'] = $alias;
                    $array[$group][$alias]['type']  = $field->getType();
                }
            } else {
                if ($field['isPublished']) {
                    $group = $field['group'];
                    $array[$group][$alias]['id']    = $field['id'];
                    $array[$group][$alias]['group'] = $group;
                    $array[$group][$alias]['label'] = $field['label'];
                    $array[$group][$alias]['alias'] = $alias;
                    $array[$group][$alias]['type']  = $field['type'];
                }
            }
        }
        return $array;
    }

    /**
     * Returns flat array for single lead
     *
     * @param $leadId
     */
    public function getLead($leadId)
    {
        return $this->getRepository()->getLead($leadId);
    }

    /**
     * Get the current lead; if $returnTracking = true then array with lead, trackingId, and boolean of if trackingId
     * was just generated or not
     *
     * @return Lead|array
     */
    public function getCurrentLead($returnTracking = false)
    {
        static $lead;

        $request = $this->factory->getRequest();
        $cookies = $request->cookies;

        list($trackingId, $generated) = $this->getTrackingCookie();

        if (empty($lead)) {
            $leadId = $cookies->get($trackingId);
            $ip     = $this->factory->getIpAddress();
            if (empty($leadId)) {
                //this lead is not tracked yet so get leads by IP and track that lead or create a new one
                $leads = $this->getLeadsByIp($ip->getIpAddress());

                if (count($leads)) {
                    //just create a tracking cookie for the newest lead
                    $lead   = $leads[0];
                    $leadId = $lead->getId();
                } else {
                    //let's create a lead
                    $lead = new Lead();
                    $lead->addIpAddress($ip);
                    $this->saveEntity($lead);
                    $leadId = $lead->getId();
                }
            } else {
                $lead = $this->getEntity($leadId);
                if ($lead === null) {
                    //let's create a lead
                    $lead = new Lead();
                    $lead->addIpAddress($ip);
                    $this->saveEntity($lead);
                    $leadId = $lead->getId();
                }
            }
            $this->setLeadCookie($leadId);
        }
        return ($returnTracking) ? array($lead, $trackingId, $generated) : $lead;
    }

    /**
     * Get or generate the tracking ID for the current session
     *
     * @return array
     */
    public function getTrackingCookie()
    {
        $request = $this->factory->getRequest();
        $cookies = $request->cookies;

        //check for the tracking cookie
        $trackingId = $cookies->get('mautic_session_id');
        $generated  = false;
        if (empty($trackingId)) {
            $trackingId = uniqid();
            $generated  = true;
        }

        //create a tracking cookie
        $expire = time() + 1800;
        setcookie('mautic_session_id', $trackingId, $expire);

        return array($trackingId, $generated);
    }

    /**
     * Sets the leadId for the current session
     *
     * @param $leadId
     */
    public function setLeadCookie($leadId)
    {
        list($trackingId, $generated) = $this->getTrackingCookie();
        setcookie($trackingId, $leadId, time() + 1800);
    }

    /**
     * @param $lead
     * @param $lists
     */
    public function addToLists($lead, $lists)
    {
        $leadListModel = $this->factory->getModel('lead.list');
        $leadListRepo  = $leadListModel->getRepository();

        if (!$lists instanceof LeadList) {
            if (!is_array($lists)) {
                $lists = array($lists);
            }

            //make sure they are ints
            foreach ($lists as &$l) {
                $l = (int) $l;
            }

            $listEntities = $leadListModel->getEntities(array(
                'filter' => array(
                    'force' => array(
                        array(
                            'column' => 'l.id',
                            'expr'   => 'in',
                            'value'  => $lists
                        )
                    )
                )
            ));

            foreach ($listEntities as $list) {
                $list->addLead($lead);
            }
            $leadListRepo->saveEntities($listEntities);
        } else {
            $lists->addLead($lead);
            $leadListRepo->saveEntity($lists);
        }
    }

    /**
     * @param $lead
     * @param $lists
     */
    public function removeFromLists($lead, $lists)
    {
        $leadListModel = $this->factory->getModel('lead.list');
        $leadListRepo  = $leadListModel->getRepository();

        if (!$lists instanceof LeadList) {
            if (!is_array($lists)) {
                $lists = array($lists);
            }

            //make sure they are ints
            foreach ($lists as &$l) {
                $l = (int)$l;
            }

            $listEntities = $leadListModel->getEntities(array(
                'filter' => array(
                    'force' => array(
                        array(
                            'column' => 'l.id',
                            'expr'   => 'in',
                            'value'  => $lists
                        )
                    )
                )
            ));

            foreach ($listEntities as $list) {
                $list->removeLead($lead);
            }

            $leadListRepo->saveEntities($listEntities);
        } else {
            $lists->removeLead($lead);
            $leadListRepo->saveEntity($lists);
        }
    }
}