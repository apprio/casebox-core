Ext.namespace('CB.object.edit');

Ext.define('CB.object.edit.View', {
    extend: 'Ext.Panel'

    ,alias: 'CBObjectEditView'
    ,xtype: 'CBObjectEditView'

    ,border: false
	
	,scrollable:true //Added by Apprio for long fields

    ,closeAction: 'hide'

    //object data
    ,data: {}

    ,initComponent: function(){
        this.initActions();

        this.viewToolbar = new Ext.Toolbar({
            border: false
            ,style: 'background: #ffffff'
            ,defaults: {
                scale: 'medium'
            }
            ,items: this.getToolbarButtons()
        });

        this.editForm = Ext.create('CB.object.edit.Form', {
            border: false
            ,hideTitle: true
            ,listeners: {
                scope: this
                ,loaded: this.onObjectLoaded
                ,saveobject: this.onSaveObjectEvent
            }
        });

        Ext.apply(this, {
            tbar: this.viewToolbar
            ,items: [this.editForm]
        });

        this.callParent(arguments);

        this.enableBubble(['loaded']);
    }

    ,getViewInfo: function() {
        return {
            path: '-1'
            ,pathtext: Ext.valueFrom(this.editForm.data.name, L.Edit)
        };
    }

    ,initActions: function() {

        this.actions = {
            save: new Ext.Action({
                text: L.Save
                ,iconCls: 'icon-save'
                ,scale: 'medium'
                ,scope: this
                ,handler: this.onSaveClick
            })

            ,cancel: new Ext.Action({
                text: L.Cancel
                ,iconCls: 'i-cancel'
                ,scale: 'medium'
                ,scope: this
                ,handler: this.onCancelClick
            })
        };
    }

    ,getToolbarButtons: function() {
        var rez = [
            this.actions.save
            ,this.actions.cancel
        ];

        return rez;
    }

    ,onObjectLoaded: function(form) {
        this.fireEvent('loaded', this);
    }

    ,getSelection: function() {
        return [this.editForm.data];
    }

    ,isDirty: function() {
        return this.editForm._isDirty;
    }

    ,onSaveObjectEvent: function(objComp, ev) {
        ev.stopEvent();
        if(this.actions.save.isDisabled()) {
            return false;
        }
        this.onSaveClick();
    }

    ,onSaveClick: function(b, e) {
    	this.actions.save.setDisabled(true);    	
		var rez = this.editForm.isValid();	
		if (!rez)
		{
		  this.actions.save.setDisabled(false);				
		  return this.editForm.grid.focusInvalidRecord();
		  //Ext.Msg.alert(L.Error, L.RequiredFieldsMessage);	  
		  //return false;
		}
		this.editForm.save(this.onAfterConfirming, this);
    }

    ,onCancelClick: function(b, e) {
        this.editForm.confirmDiscardChanges(this.onAfterConfirming.bind(this));
    }

    ,confirmDiscardingChanges: function(callback) {
        this.confirmationCallback = callback;
        this.editForm.confirmDiscardChanges(this.onAfterConfirming.bind(this));
    }

    ,onAfterConfirming: function() {
    	this.actions.save.setDisabled(false);
        if (this.confirmationCallback) {
            this.confirmationCallback();
        } else {
            this.fireEvent('actionconfirmed', this);
        }
    }
});
