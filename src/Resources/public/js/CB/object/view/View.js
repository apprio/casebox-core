	Ext.namespace('CB.object.view');

	Ext.define('CB.object.view.View', {
    extend: 'Ext.Panel'
	,alias: 'widget.CBObjectViewView'
	,api: 'CB_Objects.getPluginsData'
    ,border: false
    ,layout: 'card'
    ,activeItem: 0

    ,constructor: function() {

        Ext.apply(this, {
            loadedData: {}
        });

        this.callParent(arguments);
    }

    ,initComponent: function() {

	 Ext.apply(this, {
            layout: {
                type: 'vbox'
                ,align: 'stretch'
            }
        });

        this.callParent(arguments);

        App.on('filesuploaded', this.onFilesUploaded, this);
        App.on('objectsaction', this.onObjectsAction, this);

        this.delayLoadTask = new Ext.util.DelayedTask(this.doLoad, this);
		//CB_Objects.getPluginsData

        this.enableBubble(['changeparams', 'filedownload', 'createobject']);

        App.mainViewPort.on('objectsdeleted', this.onObjectsDeleted, this);
        App.on('objectchanged', this.onObjectChanged, this);
        App.on('objectsaction', this.onObjectsAction, this);

        App.Favorites.on('change', this.onFavoritesChange, this);
    }

    /**
     * on show
     * @param  component
     * @return void
     */
    ,onShowEvent: function(c) {
        if(this.lastLoadData) {
            this.load(this.lastLoadData);
        }
    }

    /**
     * remove listeners on destroy
     * @param  component
     * @return void
     */
    ,onBeforeDestroy: function(c) {
        App.mainViewPort.un('objectsdeleted', this.onObjectsDeleted, this);
        App.un('objectchanged', this.onObjectChanged, this);
        App.un('objectsaction', this.onObjectsAction, this);
    }

    /**
     * change active view (properties/preview)
     * @param int index
     * @param bool autoLoad
     */
    ,setActiveView: function(index, autoLoad){
        var currentItemIndex = this.items.indexOf(this.getLayout().activeItem);

        if(currentItemIndex == index) {
            return;
        }

        this.clear();

        this.getLayout().setActiveItem(index);

        this.onViewChange();

        if(autoLoad !== false) {
            this.load(this.requestedLoadData);
        }
    }

    /**
     * adjustments on view change
     * @return void
     */
    ,onViewChange: function() {
        var d = this.loadedData;
        //this.actions.edit.setDisabled(isNaN(d.id) || Ext.isEmpty(d.id));
        //this.BC.get('preview').toggle(this.loadedData.viewIndex == 1, false);
    }

    /**
     * clear function
     * @return {[type]} [description]
     */
    ,clear: function() {
        this.delayedLoadTask.cancel();
        delete this.locked;
        delete this.requestedLoadData;
        this.previousLoadedData = Ext.clone(this.loadedData);
        this.loadedData = {};
        this.getLayout().activeItem.clear();
        this.updateToolbarAndMenuItems();
    }

    /**
     * loading an object into the panel in a specific view
     * @param  {[type]} objectData
     * @return {[type]}
     */
    ,load: function (params) {
        if(!isNaN(params)) {
            params = {id: params};
        }

        this.delayLoadTask.cancel();

        /* check if not the same as current params */
        if(Ext.encode(params) == Ext.encode(this.loadedParams)) {
            return;
        }

        /* delay task */
        this.delayLoadTask.delay(60, this.doLoad, this, arguments);

    }

    /**
     * direct loading method of the this.requestedLoadData
     * @return void
     */
    ,doLoad: function(params) {
		this.api = CB_Objects.getPluginsData;
        if(Ext.isEmpty(this.api)) {
            return;
        }

        if(!isNaN(params)) {
            params = {id: params};
        }

        this.clear();

        if(Ext.isEmpty(params) || (Ext.isEmpty(params.id) && Ext.isEmpty(params.template_id))) {
            this.fireEvent('loaded', this);
            return;
        }

        this.loadedParams = params; //Ext.apply({}, params);
        this.api(params, this.onLoadData, this);
    }


    ,onLoadData: function(r, e) {
        var items = []
            ,params = Ext.valueFrom(this.loadedParams, {});

        //check if object was found (success = true)
        if(!r || (r.success !== true)) {
            this.update('<div class="x-preview-mask">' + L.RecordIdNotFound.replace('{id}', '#' + params.id) + '</div>');

        } else {
            var commonInfo = r.common
                ? r.common.data
                : Ext.valueFrom(r.data.systemProperties, {}).data;

            Ext.apply(params, commonInfo);

            this.removeAll(true);

            this.createMenu = r.menu;
			/*var vxTabs = Ext.getCmp('vxTabs');
			if(vxTabs){
				vxTabs.destroy();
			}*/
			var tabPanel = Ext.create('Ext.tab.Panel', {
				stateEvents: ['tabchange'],
				stateful:true,
				autoScroll: false,
				itemId:'vxTabs',
				listeners: {
				'tabchange': function(){
					var a = this.getActiveTab();
					var idx = this.items.indexOf(a);
					Ext.state.Manager.set('active_tab', idx);
				}},
				items: []
			});
			//527,289,311,607,61,510,533,553,482,1120,455,505,559,489,440,656,1175,651,172
			var addressesMenu = '311';
			var familymembersMenu = '289';
			var assessmentMenu = '510,533,553,482,455,1120,505,559,489,440,656,1175,651,172,3114'; //removed 1120 FEMA
			var taskMenu = '7';
			var recoveryMenu = '527'
			var referralMenu = '607';
			var familymembersData=[];
			familymembersData.data = [];
			familymembersData.limit = 100;
			var addressesData=[];
			addressesData.data = [];
			addressesData.limit = 100;
			var assessmentData=[];
			assessmentData.data = [];
			assessmentData.limit = 100;
			var referralData=[];
			referralData.data = [];
			referralData.limit = 100;
			var recoveryReferralData=[];
			recoveryReferralData.data = [];
			recoveryReferralData.limit = 100;
			var recoveryCompletedData=[];
			recoveryCompletedData.data = [];
			recoveryCompletedData.limit = 100;
			var recoveryData=[];
			recoveryData.data = [];
			recoveryData.limit = 100;
			if (Ext.isDefined(r.data.contentItems))
			{
				Ext.iterate(
					r.data.contentItems.data
					,function(k, v, o) {
					   if (familymembersMenu.indexOf(k.template_id) >=  0)
					   {
						   familymembersData.data.push(k);
					   }
					   if (addressesMenu.indexOf(k.template_id) >=  0)
					   {
						   addressesData.data.push(k);
					   }
					   if (assessmentMenu.indexOf(k.template_id) >=  0 && k.template_id != 311)
					   {
						   assessmentData.data.push(k);
						   assessmentMenu = assessmentMenu.replace(k.template_id + ',','');
						   assessmentMenu = assessmentMenu.replace(","+k.template_id,'');
						   assessmentMenu = assessmentMenu.replace(k.template_id,'');
					   }
					   if (referralMenu.indexOf(k.template_id) >=  0)
					   {
		 				if (k.name.indexOf(' -  [') >= 0)
		 				{
		 					referralData.data.push(k);
		 				}
						else if (k.name.indexOf(' []') >= 0)
						{
							recoveryReferralData.data.push(k);
						}
		 				else
		 				{
							recoveryCompletedData.data.push(k);
		 				}
					   }
					   if (recoveryMenu.indexOf(k.template_id) >=  0)
					   {
							recoveryData.data.push(k);
					   }
				}
					,this
				);
			}

			var c= Ext.create('Ext.panel.Panel', {
				title: L.Facesheet,
				layout: {
					align: 'stretch',
					type: 'vbox'
				},
			});

			var content = Ext.create('CBObjectPluginObjectProperties',{params:params});
			r.data.objectProperties.data.preview[0] = r.data.objectProperties.data.preview[2];
			content.onLoadData(r.data.objectProperties);
			c.add(content);

			/*if (Ext.isDefined(r.data.comments))
			{
			content.title = 'Comments' + ' [' +r.data.comments.data.length+']';
			content.onLoadData(r.data.comments);
			}
			c.add(content);
			content.updateTitle('Notes');*/
			items.push(c);



			// Assessments

			c= Ext.create('Ext.panel.Panel', {
				title: L.Assessments + ' [' +assessmentData.data.length+']',
				layout: {
					align: 'stretch',
					type: 'vbox'
				},
			});

			content = Ext.create('CBObjectPluginObjectProperties',{params:params});
			r.data.objectProperties.data.preview[0] = r.data.objectProperties.data.preview[3];
			content.onLoadData(r.data.objectProperties);
			c.add(content);


			if(!Ext.isEmpty(r.data.objectProperties.data.can.assessments)) {
				//assessmentMenu = r.data.objectProperties.data.can.assessments;
				var templatesStore = CB.DB.templates;
				var tbdAssessmentData = [];
				var tbdAssessments = {};
				for(var a = 0; a < r.data.objectProperties.data.can.assessments.length; a++){
					tbdAssessmentData[a] = {};
					var templateId = r.data.objectProperties.data.can.assessments[a];
					var templateName = templatesStore.getProperty(templateId,'title');
					var iconCls = CB.DB.templates.getIcon(templateId);
					tbdAssessmentData[a].template_id = templateId;
					tbdAssessmentData[a].name = templateName;
					tbdAssessmentData[a].pid = r.data.objectProperties.data.id;
					tbdAssessmentData[a].ago_text = L.ToBeCreated;
					tbdAssessmentData[a].user = '';
					tbdAssessmentData[a].id = null;
					//rez += '<img class="i16u ' + iconCls + '" src="/css/i/s.gif">'+templateName +'';
				}
				tbdAssessments.data =tbdAssessmentData;
				tbdAssessments.limit = 100;
				content  = Ext.create('CBObjectPluginContentItems',{params: params})
				content.createMenu = assessmentMenu;
				content.updateTitle(L.ClientAssessmentsNeeded);
				content.onLoadData(tbdAssessments);
				c.add(content);
			}
			content  = Ext.create('CBObjectPluginContentItems',{params: params})
			content.createMenu = assessmentMenu;
			content.updateTitle(L.ClientAssessmentsCompleted);
			content.onLoadData(assessmentData);
			c.add(content);
			items.push(c);

			// Recovery

			c= Ext.create('Ext.panel.Panel', {
				title: L.Referrals + '/' + L.Recovery + ' [' + (parseInt(recoveryReferralData.data.length) + parseInt(recoveryCompletedData.data.length)) +']',
				layout: {
					align: 'stretch',
					type: 'vbox'
				},
			});

			content = Ext.create('CBObjectPluginObjectProperties',{params:params});
			r.data.objectProperties.data.preview[0] = r.data.objectProperties.data.preview[5];
			content.onLoadData(r.data.objectProperties);
			c.add(content);

			if(!Ext.isEmpty(r.data.objectProperties.data.can.recovery)) {
				var templatesStore = CB.DB.templates;
				var tbdRecoveryData = [];
				var tbdRecovery = {};
				for(var a = 0; a < r.data.objectProperties.data.can.recovery.length; a++){
					tbdRecoveryData[a] = {};
					var templateId = r.data.objectProperties.data.can.recovery[a];
					var templateName = templatesStore.getProperty(templateId,'title');
					var iconCls = CB.DB.templates.getIcon(templateId);
					tbdRecoveryData[a].template_id = templateId;
					tbdRecoveryData[a].name = templateName;
					tbdRecoveryData[a].pid = r.data.objectProperties.data.id;
					tbdRecoveryData[a].ago_text = L.ToBeCreated;
					tbdRecoveryData[a].user = '';
					tbdRecoveryData[a].id = null;
					//rez += '<img class="i16u ' + iconCls + '" src="/css/i/s.gif">'+templateName +'';
				}
				tbdRecovery.data =tbdRecoveryData;
				content  = Ext.create('CBObjectPluginContentItems',{params: params})
				content.createMenu = recoveryMenu;
				content.updateTitle(L.ClientRecoveryNeeded);
				content.onLoadData(tbdRecovery);
				c.add(content);
			}

			content  = Ext.create('CBObjectPluginContentItems',{params: params})
			content.createMenu = referralMenu;
			//content.actions.add.setHidden(true);
			content.updateTitle(L.ClientReferrals);
			content.onLoadData(referralData);
			c.add(content);

			content  = Ext.create('CBObjectPluginContentItems',{params: params})
			content.createMenu = referralMenu;
			//content.actions.add.setHidden(true);
			content.updateTitle(L.ReferralsMade);
			content.onLoadData(recoveryReferralData);
			c.add(content);

		 	content  = Ext.create('CBObjectPluginContentItems',{params: params})
			content.createMenu = referralMenu;
		 	//content.actions.add.setHidden(true);
		 	content.updateTitle(L.CompletedReferrals);
		 	content.onLoadData(recoveryCompletedData);
		 	c.add(content);

			content  = Ext.create('CBObjectPluginContentItems',{params: params})
			content.createMenu = recoveryMenu;
			content.updateTitle(L.RecoveryNotes);
			content.onLoadData(recoveryData);

 			c.add(content);
 			content  = Ext.create('CBObjectPluginContentItems',{params: params})
 			content.createMenu = taskMenu;
 			content.updateTitle(L.ClientTasks);

 			content.onLoadData(r.data.tasks);
 			c.add(content);
 			items.push(c);


			// Timeline

			c= Ext.create('Ext.panel.Panel', {
				title: L.Timeline,
				layout: {
					align: 'stretch',
					type: 'vbox'
				},
			});

         this.store = new Ext.data.DirectStore({
             autoLoad: true
             ,autoDestroy: true
             ,remoteSort: true
             ,sortOnLoad: false
             ,extraParams: {}
             ,pageSize: 100
             ,model: 'Items'
             ,proxy: new  Ext.data.DirectProxy({
                paramsAsHash: true
                 ,directFn: CB_BrowserView.getChildren
						,extraParams: {
						  facets:'general'
					  ,pid:params.id
					  ,path:params.id
					  ,from:'activityStream'
					  ,userViewChange:true
					  ,query:null
					  ,page:1
					  ,start:0
					  ,limit:25
					  ,sort:[{property:'last_action_tdt',direction:'DESC'}]
}
                 ,reader: {
                    type: 'json'
                     ,successProperty: 'success'
                     ,idProperty: 'nid'
                     ,rootProperty: 'data'
                     ,messageProperty: 'msg'
                 }
             })
         });

			content = Ext.create('CBBrowserViewActivityStream',{store: this.store});
			c.add(content);
			items.push(c);



            if(!Ext.isEmpty(items)) {
                tabPanel.add(items);
            }

			var activeTab =0;
			if (Ext.isDefined(Ext.state.Manager.get('active_tab')) && !isNaN(Ext.state.Manager.get('active_tab')))
			{
				activeTab = Ext.state.Manager.get('active_tab', 0);
			}

			tabPanel.setActiveTab(activeTab);

			 //tabPanel.tabBar.items.each(function(card){
             //           card.setDisabled(true);
             //       });

			this.add(tabPanel);


            /**
             * we make this check for title after all plugins have been added
             * because objectProperties plugin applies loaded data (including object name)
             * to the params
             */
            if(params &&
                // (CB.DB.templates.getType(params.template_id) !== 'task') &&
                (params.from !== 'window') &&
                !Ext.isEmpty(params.name)
            ){

				if (r.data.objectProperties.data.preview[1] != null) {
					Ext.apply(params, r.data.objectProperties.data);
					var previewHtml = r.data.objectProperties.data.preview[1];
					params.preview = previewHtml;
				}
				else
				{
					params.preview = "None";
				}

				var data = Ext.copyTo(
                    {}
                    ,params
                    ,'id,pids,path,name,template_id,status,statusCls,cid,cdate_ago_text,uid,udate_ago_text,preview,can'
                );

					var titleView =  new Ext.panel.Panel({
					width: 300,
					margin: 0,
					bodyPadding: 0,
					border: false,
					header: false,
					tpl: [
						,'<div class="obj-header" style="text-align:left"><b class="{titleCls}">{[ Ext.String.htmlEncode(Ext.valueFrom(values.name, \'\')) ]}</b> &nbsp;'
							,'{[ this.getStatusInfo(values) ]}'
							,'{preview}'
							,'</div>'
						,'</div>'
						 ,{
                getStatusInfo: function (values) {
						if(Ext.isEmpty(values.status)) {
							return '';
						}

						var rez = '<div class="dIB fs12 ' + Ext.valueFrom(values.statusCls, '') + '"">' +
							values.status + '</div>';

						return rez;
					}
						 ,
                getTaskInfo: function (values) {
						var rez = '';
						if(Ext.isEmpty(values.can.assessments)) {
							return '';
						}
						var templatesStore = CB.DB.templates;
						for(var a = 0; a < values.can.assessments.length; a++){
							var templateId = values.can.assessments[a];
							var templateName = templatesStore.getProperty(templateId,'title');
							var iconCls = CB.DB.templates.getIcon(templateId);
							rez += '<img alt="Template" class="i16u ' + iconCls + '" src="/css/i/s.gif">'+templateName +'';
						}

						return rez;
					}
                ,getTitleInfo: function (values) {
						var rez = [];

						// #Id
						if(values.id) {
							rez.push('#' + values.id);
						}

						// Creator
						//if (values.cid) {
						//	rez.push(
						//		L.CreatedBy + ' ' +
						//		CB.DB.usersStore.getName(values.cid) + ' ' +
						//		Ext.valueFrom(values.cdate_ago_text, '')
						//	);
						//}

						// Updater
						if (values.uid) {
							rez.push(
								L.UpdatedBy + ' ' +
								CB.DB.usersStore.getName(values.uid) + ' ' +
								Ext.valueFrom(values.udate_ago_text, '')
							);
						}

						return rez.join(' - ');
					}
            }
					],
					html: ''
				});

				titleView.update(Ext.valueFrom(data, {}));
                this.insert(0, titleView);
            }

            this.doLayout(true, true);
        }

        this.fireEvent('loaded', this, params);
    }

    ,clear: function() {
        this.removeAll(true);
        delete this.loadedParams;
    }

    ,onFilesUploaded: function(pids) {
        if(!Ext.isEmpty(this.loadedParams)) {
            if(pids.indexOf(String(this.loadedParams.id)) >=0 ) {
                this.reload();
            }
        }
    }

    ,onObjectsAction: function(action, data, e) {
        if(this.loadedParams && (data.targetId == this.loadedParams.id)) {
            this.reload();
        }
    }

    ,reload: function() {
        this.doLoad(this.loadedParams);
    }

    /**
     * method to be overriden for returning needed buttons for container toolbar
     * @return object
     *         Example: rez = {
     *              tbar: {}
     *              ,menu: {
     *                   reload: {}
     *              }
     *          }
     */
    ,getContainerToolbarItems: function() {

    }


    ,onPluginsContainerLoaded: function(cmp, params) {
        this.loadedData.subscription = params.subscription;
        this.onCardItemLoaded(cmp);
    }
    /**
     * adjustments on view loaded
     * @param  object item
     * @return void
     */
    ,onCardItemLoaded: function(item) {
        this.locked = false;

        this.updateToolbarAndMenuItems();

        this.fireEvent('loaded', this, item);

        if(Ext.isEmpty(this.loadedData) || Ext.isEmpty(this.loadedData.scroll)) {
            return;
        }
        if(item.body) {
            item.body.scrollTo('left', this.loadedData.scroll.left);
            item.body.scrollTo('top', this.loadedData.scroll.top);
        }
    }

    /**
     * update toolbar and menu item corresponding to active view
     * @return void
     */
    ,updateToolbarAndMenuItems: function() {
        var ai = this.getLayout().activeItem;

        if(this.menu) {
            this.menu.removeAll(true);
            this.menu.destroy();
        }

        this.menu = new Ext.menu.Menu({items:[]});

        //this.BC.get('more').setMenu(this.menu, true);

        //hide all by default

    }

    /**
     * update create menu under the points button
     * @return {[type]} [description]
     */
    ,updateCreateMenu: function() {
        if(!this.menu) {
            return;
        }

        var nmb = this.menu.child('[name="newmenu"]');

        if(nmb) {
            updateMenu(
                nmb
                ,this.getLayout().activeItem.createMenu
                ,this.onCreateObjectClick
                ,this
            );

            nmb.setDisabled(nmb.menu.items.getCount() < 1);
        }
    }

    /**
     * edit an item
     * Shold be reviewed and merge with next method
     * or even moved/merged to mainViewPort component
     * @param  object objectData
     * @param  event e
     * @return void
     */
    ,edit: function (objectData, e) {
        objectData.view = 'edit';

        this.openObjectWindow(objectData);
    }

    /**
     * open an object edit window with given data
     * @param  object objectData
     * @return void
     */
    ,openObjectWindow: function(objectData) {
        var data = Ext.apply({}, objectData);
        //edit object in popup window
        delete data.html;

        App.windowManager.openObjectWindow(data);
    }

    /**
     * handler for edit button click
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onEditClick: function(b, e) {
        var p = Ext.apply({}, this.loadedData);

        switch(detectFileEditor(p.name)) {
            case 'webdav':
                App.openWebdavDocument(p);
                break;

            default:
               // p.comment = this.getCommentValue();

               // this.setCommentValue('');

                this.edit(p, e);
                break;
        }
    }

    /**
     * handler for reload button
     * Reloads active view (properties or preview)
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onReloadClick: function(b, e) {
        this.reload(); //don't think we need this
    }

    /**
     * handler for rename button
     * Open permissions window for loaded item by calling viewport method
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onRenameClick: function(b, e) {
        var data = {
            path: this.loadedData.id
            ,name: Ext.util.Format.htmlDecode(this.loadedData.name)
            ,scope: this
            ,callback: function(r, e) {
                this.loadedData.name = r.data.newName;
            }
        };

        App.promptRename(data);
    }

    /**
     * handler for permissions button
     * Open permissions window for loaded item by calling viewport method
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onPermissionsClick: function(b, e) {
        App.mainViewPort.openPermissions(this.loadedData.id);
    }

    /**
     * event handler for objects deletion
     *
     * There is no need to reload this view because the grid will reload and change the selection,
     * but need to cancel the edit
     *
     * @param  array ids
     * @param  object e
     * @return void
     */
    ,onObjectsDeleted: function(ids, e) {
        if(!Ext.isEmpty(this.loadedData) && setsHaveIntersection(ids, this.loadedData.id)) {
            // delete this.locked;
            this.setActiveView(0, false);
            this.clear();
            // this.loadedData = {};
            // this.items.getAt(0).clear();
            // this.updateToolbarAndMenuItems();
        }
    }

    /**
     * handler for fit image toolbar button
     * toggle fit image preview
     * This method actually should be managed by preview component
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onFitImageClick: function(b, e) {
        var ai = this.getLayout().activeItem;
        if(ai.onFitImageClick) {
            ai.onFitImageClick(b, e);
        }
    }

    /**
     * handler for preview toolbar button
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onPreviewClick: function(b, e) {
        var p = Ext.clone(this.loadedData);

        p.viewIndex = b.pressed
            ? 1
            : 0;

        this.delayedLoadTask.cancel();
        this.requestedLoadData = p;

        this.doLoad();
    }

    /**
     * handler for open external toolbar button
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onOpenExternalClick: function(b, e) {
        var d = Ext.apply({}, this.loadedData);

        d.comment = this.getCommentValue();

        this.setCommentValue('');

        d.popOut = true;
        d.view = 'edit';

        this.openObjectWindow(d);
    }

    /**
     * handler for open preview from components below
     *
     * It was opening preview in current component,
     * when editing on the right side was available.
     * Now it opens popup window in preview mode.
     *
     * @param  object data
     * @param  event e
     * @return void
     */
    ,onOpenPreviewEvent: function(data, e) {
        if(Ext.isEmpty(data)) {
            data = this.loadedData;
        }

        if(this.loadedData && (data.id == this.loadedData.id)) {
            Ext.applyIf(data, this.loadedData);
        }

        App.windowManager.openObjectWindow(Ext.clone(data), e);
    }

    /**
     * handler for open edit object event from components below
     *
     * It was opening edit in current component,
     * when editing on the right side was available.
     * Now it opens popup window in edit mode.
     *
     * @param  object data
     * @param  event e
     * @return void
     */
    ,onEditObjectEvent: function(data, e) {
        if(e) {
            e.stopEvent();
        }

        if(Ext.isEmpty(data)) {
            data = this.loadedData;
        }

        var p = Ext.clone(data);

        this.edit(p, e);
    }

    /**
     * handler for download toolbar button
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onDownloadClick: function(b, e) {
        this.fireEvent('filedownload', [this.loadedData.id], false, e);
    }

    /**
     * handler for creating new items from dropdown menu
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onCreateObjectClick: function(b, e) {
        this.goBackOnSave = true;

        var d = b.config.data;
        d.pid = this.loadedData.id;
        d.path = this.loadedData.path;
        this.fireEvent('createobject', d, e);
    }

    /**
     * handler for close task toolbar button
     * It is available when an active task is loaded.
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onCloseTaskClick: function(b, e) {
        this.getEl().mask(L.CompletingTask + ' ...', 'x-mask-loading');
        CB_Tasks.close(this.loadedData.id, this.onTaskChanged, this);
    }

    /**
     * handler for reopen a closed task toolbar button
     * It is available when a closed task is loaded.
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onReopenTaskClick: function(b, e) {
        this.getEl().mask(L.ReopeningTask + ' ...', 'x-mask-loading');
        CB_Tasks.reopen(this.loadedData.id, this.onTaskChanged, this);
    }

    /**
     * handler for completing task toolbar button
     * It is available when a task is loaded.
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onCompleteTaskClick: function(b, e) {
        CB_Tasks.complete(
            {
                id: this.loadedData.id
                ,message: ''
            }
            ,this.onTaskChanged
            ,this
        );
    }

    /**
     * common handler for task actions responce
     *
     * @param  responce r
     * @param  event e
     * @return void
     */
    ,onTaskChanged: function(r, e){
        this.getEl().unmask();
        App.fireEvent('objectchanged', this.loadedData, this);
    }

    ,onSubscriptionButtonClick: function(b, e) {
        var type = (b.itemId === 'notifyOn')
            ? 'watch'
            : 'ignore';

        CB_Objects.setSubscription(
            {
                objectId: this.loadedData.id
                ,type: type
            }
            ,function(r, e) {
                if(!r || (r.success !== true)) {
                    return;
                }

                this.actions.notifyOn.setHidden(type === 'watch');
                this.actions.notifyOff.setHidden(type === 'ignore');
            }
            ,this
        );
    }

    /**
     * handler for WebDav Link menu button click
     * Shows a window with link for WebDav editing
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onWebDAVLinkClick: function(b, e) {
        App.openWebdavDocument(
            this.loadedData
            ,false
        );
    }

    /**
     * handler for Permalink menu button click
     * Shows a prompt window with permalink
     *
     * @param  button b
     * @param  event e
     * @return void
     */
    ,onPermalinkClick: function(b, e) {
        window.prompt(
            'Copy to clipboard: Ctrl+C, Enter'
            , window.location.origin + '/c/' + App.config.coreName + '/view/' + this.loadedData.id + '/');
    }

    /**
     * handler for lockpanel event
     * Current component wouldnt accept any load requests when locked
     *
     * @param  boolean status
     * @return void
     */
    ,onLockPanelEvent: function(status) {
        this.locked = status;
    }

    /**
     * method for setting selected file version on contained versions plugin
     * This method was used by files editing window
     * Now it would be changed/removed
     *
     * @param object params
     */
    ,setSelectedVersion: function(params) {
        var ai = this.getLayout().activeItem;
        if(ai.isXType('CBObjectProperties')) {
            ai.setSelectedVersion(params);
        }
    }

    /**
     * handler for objects action event (move/copy)
     *
     * @param  object r responce
     * @param  event e
     * @return void
     */
    ,onObjectsAction: function(action, r, e){
        if(this.loadedData.id == r.targetId) {
            this.onReloadClick();
        }
    }

    /**
     * handler for global object change event
     * Here we react and reload the view if necessary
     * @param  object data
     * @param  component component
     * @return void
     */
    ,onObjectChanged: function(data, component) {
        if(!isNaN(data)) {
            data = {id: data};
        }

        if(!Ext.isEmpty(data.isNew) && (data.type !== 'time_tracking')) {
            this.requestedLoadData = data;
            this.doLoad();
            return;
        }
        this.onReloadClick();
        /*if(!Ext.isEmpty(this.loadedData)) {
            if((data.pid == this.loadedData.id) || (data.id == this.loadedData.id)) {
                this.onReloadClick();
            }
        }*/
    }

    /**
     * close this view container (hides it)
     * @return void
     */
    ,onCloseClick: function() {
        this.collapse();
    }

    ,onSetOwnershipClick: function(b, e) {
        if(!Ext.isEmpty(this.loadedData.id)) {
            CB_Objects.setOwnership(
                {
                    ids: this.loadedData.id
                    ,userId: b.userId
                }
                ,this.processSetOwnership
                ,this
            );
        }
    }

    ,processSetOwnership: function(r, e) {
        if(r && r.success) {
            App.fireEvent('objectchanged', this.loadedData, this);
        }
    }

    ,onStarClick: function(b, e) {
        var ld = this.loadedData
            ,d = {
                id: ld.id
                ,name: ld.name
                ,iconCls: ld.iconCls
                ,path: '/' + ld.pids + '/' + ld.id
                ,pathText: ld.path
            };

        App.Favorites.setStarred(d);
    }

    ,onUnstarClick: function(b, e) {
        App.Favorites.setUnstarred(this.loadedData.id);
    }

    ,onFavoritesChange: function() {
        if(this.loadedData) {
            var isStarred = App.Favorites.isStarred(this.loadedData.id);

            this.actions.star.setHidden(isStarred);
            this.actions.unstar.setHidden(!isStarred);
        }
    }
}
);
