Ext.namespace('CB');
Ext.define('CB.NoteUploader', {
    extend: 'Ext.Window'

    ,alias: 'CBNoteUploader'

    ,xtype: 'CBNoteUploader'

    ,title: L.UserDetails
    ,border: false
    ,closable: false
    ,minimizable: true
    ,width: 850
    ,height: 600

    ,initComponent: function() {

        this.cards = new Ext.Panel({
            region: 'center'
            ,border: false
            ,tbarCssClass: 'x-panel-gray'
            ,layout:'card'
            ,activeItem: 0
            ,items: [{
                xtype: 'CBNoteForm'
                ,listeners: {
                    scope: this
                    ,change: function(){/*this.autoCloseTask.delay(1000*60*5);/**/ }
					,'savesuccess': function() { this.minimize()}
                }
            }
            ]
            ,deferredRender: true
        });

        Ext.apply(
            this
            ,{
                layout: 'border'
                ,items:[
                    this.menu
                    ,this.cards

                ]
            }
        );
        this.callParent(arguments);

        /* autoclose form if no activity in 5 minutes */
        // this.autoCloseTask = new Ext.util.DelayedTask(this.destroy, this);
        // this.autoCloseTask.delay(1000*60*5);
		this.cards.getLayout().setActiveItem(0);
        CB_User.getProfileData(false, this.onGetData, this);
    }
    ,onGetData: function(r, e){
        if(!r) {
            return;
        }
        this.cards.items.getAt(0).loadData(r);
    }

    ,onMenuButtonClick: function(b, e){
        this.cards.getLayout().setActiveItem(this.menu.items.indexOf(b));
        // this.autoCloseTask.delay(1000*60*5);
    }
}
);

Ext.define('CB.NoteForm', {
    extend: 'Ext.form.FormPanel'
    ,alias: 'widget.CBNoteForm'

    ,border: false
    ,fileUpload: true
    ,scrollable: true
    ,bodyPadding: 10
    ,data: {}
    ,initComponent: function(){

        this.data = this.config.data;

        this.objectsStore = new CB.DB.ObjectsStore();

        Ext.apply(this,{
            items:[{
                xtype: 'CBVerticalEditGrid'
                ,refOwner: this
                ,width: 800
                ,style: 'margin-bottom: 50px'
                ,autoHeight: true
                ,viewConfig: {
                    forceFit: true
                    ,autoFill: true
                }

            }
            ]
            ,buttonAlign: 'left'
            ,buttons: [{
                text: L.Save
                ,scope: this
                ,handler: this.onSaveClick
            },{
                text: L.Reset
                ,scope: this
                ,handler: this.onResetClick
            }

            ]
            ,listeners: {
                scope: this
                ,afterrender: this.onAfterRender
                ,change: this.onChange
                ,verify: this.onVerifyEvent
            }
        });

        this.callParent(arguments);

        this.grid = this.items.getAt(0);
        //this.grid.templateStore.data.items[3].hidden = true;

        this.enableBubble(['verify']);
    }
    ,onAfterRender: function(cmp){

    }

    ,loadData: function(data){
        if(!Ext.isEmpty(data.assocObjects) && Ext.isArray(data.assocObjects)) {
            for (var i = 0; i < data.assocObjects.length; i++) {
                data.assocObjects[i].iconCls = getItemIcon(data.assocObjects[i]);
            }
            if (!data.assocObjects.includes({id:248273}) || !data.assocObjects.includes({id:248274})) {
              if (App.loginData.groups == '575' || App.loginData.groups == '576'){
                // Worker Group, load assigned IDCM Supervisor
                if (App.loginData.groups == '575') {
                  data.assocObjects.push({id: 248273, name: 'IDCM Worker - Level I'});
                  data.data.user_role = {value: 248273};
                } else {
                  data.assocObjects.push({id: 248702, name: 'IDCM Worker - Level II'});
                  data.data.user_role = {value: 248702};
                }
                if (App.loginData.data.assignedsupervisor) {
                    data.data.user_role.childs = {assignedsupervisor: App.loginData.data.assignedsupervisor};
                }
              } else if (App.loginData.groups == '30') {
                // Supervisor Group, assign IDCM Workers
                data.assocObjects.push({id: 248274, name: 'IDCM Worker Supervisor'});
                data.data.user_role = {value: 248274};
                if (App.loginData.data.user_role.childs.assignedworker) {
                  data.data.user_role.childs = {assignedworker: App.loginData.data.user_role.childs.assignedworker};
                }
              }
            }
            this.objectsStore.loadData(data.assocObjects);
            delete data.assocObjects;
        } else {
          if (App.loginData.groups == '575' || App.loginData.groups == '576'){
            // Worker Group, load assigned IDCM Supervisor
            data.assocObjects = [];
            if (App.loginData.groups == '575') {
              data.assocObjects.push({id: 248273, name: 'IDCM Worker - Level I'});
              data.data.user_role = {value: 248273};
            } else {
              data.assocObjects.push({id: 248702, name: 'IDCM Worker - Level II'});
              data.data.user_role = {value: 248702};
            }
            if (App.loginData.data.user_role) {
              if (App.loginData.data.user_role.childs.assignedsupervisor) {
                data.data.user_role.childs = {assignedsupervisor: App.loginData.data.user_role.childs.assignedsupervisor};
              }
            }
            this.objectsStore.loadData(data.assocObjects);
            delete data.assocObjects;
          } else if (App.loginData.groups == '30') {
            // Supervisor Group, assign IDCM Workers
            data.assocObjects = [];
            data.assocObjects.push({id: 248274, name: 'IDCM Worker Supervisor'});
            data.data.user_role = {value: 248274};
            if (App.loginData.data.user_role) {
              data.data.user_role.childs = {assignedworker: App.loginData.data.user_role.childs.assignedworker};
            }
            this.objectsStore.loadData(data.assocObjects);
            delete data.assocObjects;
          }
        }

        if(Ext.isDefined(data.language_id)) {
            data.language_id = parseInt(data.language_id, 10);
        }

        this.data = data;
        this.getForm().setValues(data);
        this.grid.reload();
        // this.syncSize();
        this.setDirty(false);
    }

    ,onSaveClick: function(){
        Ext.apply(this.data, this.getForm().getValues());

        this.grid.readValues();

        if (Ext.isDefined(this.data.data.user_role.childs)) {
          if (Ext.isDefined(this.data.data.user_role.childs.assignedworker)) {
            if (this.data.data.user_role.childs.assignedworker.indexOf(',') != -1) {
               var workers = this.data.data.user_role.childs.assignedworker.split(',');
               for (var i=0; i < workers.length; i++) {
                 var workerId = {id: workers[i]};
                 CB_UsersGroups.getUserData({data: workerId, assign: true}, this.onGetData, this);
               }
           }
            else {
              var workerId = {id: this.data.data.user_role.childs.assignedworker};
              CB_UsersGroups.getUserData({data: workerId, assign: true}, this.onGetData, this);
            }
          }
        }

        CB_User.saveProfileData(this.data, this.onSaveProcess, this);
    }

    ,onGetData: function(r, e){
        if(!r) {
            return;
        }
        if (r.success !== true) {
            if(r.verify) {
                this.fireEvent('verify', this);
            }
            return false;
        }
        if (Ext.isDefined(r.data.data.assignedsupervisor) && r.data.data.assignedsupervisor != this.data.id) {
          var data = this.data;
          var info = this;
          var workerId = r.data.id;
          Ext.Msg.confirm('Assign Worker', r.data.title + ' is already has an assigned supervisor. <br><center>Assign to yourself?', function(btn){
            if (btn == 'yes'){
              data.data.user_role.childs.assignedworker = workerId;
              Ext.apply(data, info.getForm().getValues());
              info.grid.readValues();
              CB_User.saveProfileData(data, info.onSaveProcess, info);
            } else {
                alert('Please change your selection.');
            }
          });
        }
        else {
          CB_User.saveProfileData(this.data, this.onSaveProcess, this);
        }
    }

    ,onSaveProcess: function(r, e){
        if (!r) {
            return;
        }

        if(r.success !== true) {
            if(r.verify) {
                this.fireEvent('verify', this);
            } else if(!Ext.isEmpty(r.msg)) {
                Ext.Msg.alert(L.Error, r.msg);
            }
            return;
        }
        this.setDirty(false);
        App.loginData.data = this.data.data; //add back in the note and user location
        this.fireEvent('savesuccess', this, e);
        //App.fireEvent('userprofileupdated', this.data, e);
    }

    ,onResetClick: function(){
        this.getForm().setValues('');
        this.grid.reload();
        this.loadData(this.data);
    }

    ,onChange: function(){
        this.setDirty(true);
    }

    ,setDirty: function(dirty){
        this._isDirty = (dirty !== false);

        var bbar = this.dockedItems.getAt(0);
        bbar.items.getAt(0).setDisabled(!this._isDirty);
        bbar.items.getAt(1).setDisabled(!this._isDirty);
    }

    ,onVerifyEvent: function(cmp) {
        this.destroy();
        Ext.Msg.alert(L.Info, 'User management session has expired. Please access it and authenticate again.');
    }
});

Ext.define('CB.NoteWindow', {
    extend: 'Ext.Button'
    ,alias: ['widget.notewindowbutton']
    // ,cls: 'upload-btn'

    ,initComponent: function(){


        Ext.apply(this, {
            text: L.UserDetails
            ,handler: this.showNoteWindow
            ,scope: this
        });

        this.callParent(arguments);

    }
    ,showNoteWindow: function(b, e){
        App.windowManager.openWindow({
                        xtype: 'CBNoteUploader'
                        ,id: 'noteWnd'
                    });
    }
});
