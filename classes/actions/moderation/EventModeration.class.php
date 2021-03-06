<?php

/**
 * Description of ActionModeration_EventModeration
 *
 * @author oleg
 */
class PluginModeration_ActionModeration_EventModeration extends Event {
    
    
    public function EventList()
    {
        $this->SetTemplateAction('list');
        
        $sEntity = $this->GetParam(0);
        
        $aTypesEntity = $this->PluginModeration_Moderation_GetModerationEntities();
        if(!$sEntity){
            
            if($aTypesEntity and !$this->GetParam(0)){
                $sEntity = current($aTypesEntity)->getEntity();
                Router::LocationAction(Router::GetAction() . '/list/' . $sEntity);
            }
        }
                
        if(class_exists($sEntity)){
            $oEntity = Engine::GetEntity($sEntity);            
            $this->Viewer_Assign('aModerateFields', $this->getModerateFileds($oEntity));
            $this->Viewer_Assign('sEntityName', Engine::GetEntityName($oEntity));
            $this->Viewer_Assign('sEntityClass', $sEntity);
            /*
             * Выгрузка в шаблон количества модераций
             */
            $this->Viewer_Assign('countModeration', $this->PluginModeration_Moderation_GetCountFromModerationByFilter([
                "#cache" => ['countModeration', 60*60*24],
                'entity' => $sEntity, 
                'state' => PluginModeration_ModuleModeration::STATE_MODERATE
            ]));
            $this->Viewer_Assign('countDenied', $this->PluginModeration_Moderation_GetCountFromModerationByFilter([
                "#cache" => ['countDenied', 60*60*24],
                'entity' => $sEntity, 
                'state' => PluginModeration_ModuleModeration::STATE_DENIED
            ]));
        } 
        
        $this->Viewer_Assign('sState', $this->GetParam(1, 'moderation'));
        $this->Viewer_Assign('aTypesEntity', $aTypesEntity);
        
        $this->sMenuItemSelect = $sEntity;
        
    }
    
    public function EventAjaxList()
    {                        
        $this->Viewer_SetResponseAjax('json');
        $this->SetTemplate(false);
        
        
        $iStart = getRequest('start', 0);
        $iLimit = getRequest('limit', Config::Get('moderation.talk.page_count'));
        
        $oViewer = $this->Viewer_GetLocalViewer();
        
        if(class_exists(getRequest('entity'))){
            $oEntity = Engine::GetEntity(getRequest('entity'));
            
            $aStates = [
                'moderation' => PluginModeration_ModuleModeration::STATE_MODERATE,
                'denied' => PluginModeration_ModuleModeration::STATE_DENIED
            ];
            /*
             * Фильтр поиска модераций
             */
            $aFilterModertion = [
                'entity' => getRequest('entity'),
                '#index-from' => 'entity_id',
                'state' => $aStates[getRequest('state', 'moderation')]
            ];
                        
            $aModerations = $this->PluginModeration_Moderation_GetModerationItemsByFilter($aFilterModertion);
            
            $aEntityIds = array_merge([0],array_keys($aModerations));
        
            $aEntities = $this->PluginModeration_Moderation_GetItemsByFilter([
                $oEntity->_getPrimaryKey().' in' => $aEntityIds,
                '#limit'         => [ $iStart, $iLimit],
            ], getRequest('entity'));
            
            
            //$oViewer->GetSmartyObject()->addPluginsDir(Config::Get('path.application.server').'/classes/modules/viewer/plugs');
            $oViewer->Assign('aEntities', $aEntities);
            $oViewer->Assign('sEntityClass', getRequest('entity'));
            list($aModerationFields, $sTitleField) = $this->getModerateFileds($oEntity);
            $oViewer->Assign('aModerateFields', $aModerationFields);
            $oViewer->Assign('sTitleField', $sTitleField);
//            $sHtml = $oViewer->Fetch('component@moderation:entity.list');

            $iCountAll = sizeof($aModerations);
            
            $iCount = ($iCountAll - ($iStart+$iLimit))<0?0:($iCountAll - ($iStart+$iLimit));
            
            
            $this->Viewer_AssignAjax('countAll', $iCountAll);
            $this->Viewer_AssignAjax('count', $iCount);
        } 
        
        $this->Viewer_AssignAjax('html', $oViewer->Fetch('component@moderation:entity.list'));
        
    }
    
    private function getModerateFileds($oEntity) {
        $oBehavior = $this->getBehaviorModeration($oEntity);
        return [$oBehavior->getFields(), $oBehavior->getParam('title_field')];
    }
    
    private function getBehaviorModeration($oEntity) {
        $aBehaviors = $oEntity->GetBehaviors();
        foreach ($aBehaviors as $oBehavior) {            
            if ($oBehavior instanceof PluginModeration_ModuleModeration_BehaviorEntity) {
                return $oBehavior;
            }
        }
    }
    
    public function EventAjaxPublish()
    {
        $this->Viewer_SetResponseAjax('json');
        /*
         * Получение цели модерации по параметрам из реквеста
         */
        $oModeration = $this->PluginModeration_Moderation_GetModerationByFilter([
            'entity'        => getRequest('entity'),
            'entity_id'     => getRequest('entityId')
        ]);
        /*
         * Удаление если найдена тем самым публикуем сущность
         */
        if($oModeration){
            $this->Viewer_AssignAjax('remove', $oModeration->Delete());
        }
        /*
         * Обратный  вызов после успешной модерации
         */
        if($oEntity = $oModeration->getEntityObject()){
            $sMethod = $this->getBehaviorModeration($oEntity)->getParam('callback_moderate');
            if(method_exists($oEntity, $sMethod)){
                call_user_func([$oEntity, $sMethod]);
            }
        }
        /*
         * Подсчитать количество оставшихся непромодерированных
         */
        $iModerationCount = $this->PluginModeration_Moderation_GetCountFromModerationByFilter([
            'entity'        => getRequest('entity'),
            'entity_id'     => getRequest('entityId'),
            'state'         => PluginModeration_ModuleModeration::STATE_MODERATE
        ]);
        
        $this->Viewer_AssignAjax('countAll', $iModerationCount);
    }
    
    
    public function EventAjaxDelete()
    {
        $this->Viewer_SetResponseAjax('json');
        /*
         * Получение сущности по параметрам из реквеста
         */
        $oEntity = $this->PluginModeration_Moderation_GetByFilter([
            'id' => getRequest('id')
        ], getRequest('entity'));
        /*
         * Удаление если найдена
         */
        if($oEntity){
            $this->Viewer_AssignAjax('remove', $oEntity->Delete());
        }
        /*
         * Подсчитать количество оставшихся непромодерированных
         */
        $iModerationCount = $this->PluginModeration_Moderation_GetCountFromModerationByFilter([
            'entity'        => getRequest('entity'),
        ]);
        
        $this->Viewer_AssignAjax('countAll', $iModerationCount);
    }
    
    public function EventAjaxDenied()
    {
        $this->Viewer_SetResponseAjax('json');
        /*
         * Получение цели модерации по параметрам из реквеста
         */
        $oModeration = $this->PluginModeration_Moderation_GetModerationByFilter([
            'entity'        => getRequest('entity'),
            'entity_id'     => getRequest('entityId')
        ]);
        /*
         * Изменение модерации на статус отказано
         */
        if($oModeration){
            $oModeration->setState(PluginModeration_ModuleModeration::STATE_DENIED);
            $this->Viewer_AssignAjax('remove', $oModeration->Save());
        }
        /*
         * Обратный  вызов после отказа модерации
         */
        if($oEntity = $oModeration->getEntityObject()){
            $sMethod = $this->getBehaviorModeration($oEntity)->getParam('callback_denied');
            if(method_exists($oEntity, $sMethod)){
                call_user_func_array([$oEntity, $sMethod], ['message' => getRequest('promptMessage')]);
            }
        }
        /*
         * Подсчитать количество оставшихся непромодерированных и отказаных
         */
        $iModerationCount = $this->PluginModeration_Moderation_GetCountFromModerationByFilter([
            'entity'        => getRequest('entity'),
            'entity_id'     => getRequest('entityId'),
            'state'         => PluginModeration_ModuleModeration::STATE_DENIED
        ]);
        
        $this->Viewer_AssignAjax('countAll', $iModerationCount);
    }
    
}
