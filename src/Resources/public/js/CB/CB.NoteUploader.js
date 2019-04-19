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
            }
        });

        this.callParent(arguments);

        this.grid = this.items.getAt(0);

        this.enableBubble(['verify']);
    }
    ,onAfterRender: function(cmp){

    }

    ,loadData: function(data){
        if(!Ext.isEmpty(data.assocObjects) && Ext.isArray(data.assocObjects)) {
            for (var i = 0; i < data.assocObjects.length; i++) {
                data.assocObjects[i].iconCls = getItemIcon(data.assocObjects[i]);
            }
            this.objectsStore.loadData(data.assocObjects);
            delete data.assocObjects;
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
        CB_User.saveProfileData(this.data, this.onSaveProcess, this);
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
        this.getForm().reset();
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