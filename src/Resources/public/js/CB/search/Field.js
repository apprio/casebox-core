Ext.ns('CB');

Ext.define('CB.search.Field', {

    extend: 'Ext.form.field.Text'

    ,xtype: 'CBSearchField'
    ,alias: 'widget.CBSearchField'

    ,emptyText: L.Search
    ,enableKeyEvents: true
    ,style: 'background-color: #fff'

    ,triggers: {
        clear: {
            cls: 'x-form-clear-trigger'
            ,hidden: true
            ,scope: 'this'
            ,handler: 'onTrigger1Click'
        }
        ,search: {
            cls: 'x-form-search-trigger'
            ,scope: 'this'
            ,handler: 'onTrigger2Click'
        }
         ,options: {
            cls: 'x-form-trigger'
            ,scope: 'this'
            ,handler: 'onOptionsTriggerClick'
            ,weight: -1
        }
    }

    ,initComponent : function(){
        Ext.apply(this, {
            listeners: {
                scope: this
                ,keyup: function(ed, e){
                    if(Ext.isEmpty(this.getValue())) {
                        this.triggers.clear.hide();

                    } else {
                        this.triggers.clear.show();
                    }
                }
                ,specialkey: function(ed, e){
                    switch(e.getKey()){
                        case e.ESC:
                            this.onTrigger1Click(e);
                            break;
                        case e.ENTER:
                            this.onTrigger2Click(e);
                            break;
                    }
                }
            }
        });

        this.callParent(arguments);
    }

    ,afterRender: function() {
        this.callParent(arguments);
    }

    ,setValue: function(value) {
        this.callParent(arguments);

        if (Ext.isEmpty(value)){
            this.triggers.clear.hide();
        } else {
            this.triggers.clear.show();
        }
    }

    ,onTrigger1Click : function(e){
        if(Ext.isEmpty(this.getValue())) {
            return;
        }

        this.setValue('');
        this.triggers.clear.hide();
        this.fireEvent('search', '', e);
    }

    ,onTrigger2Click : function(e){
        this.fireEvent('search', this.getValue(), this, e);
    }
    
       ,onOptionsTriggerClick : function(e){
        if(!this.optionsMenu) {
            var menuItems = [{
                text: L.Title
                ,xtype: 'menucheckitem'
                ,checked: true
                ,searchIn: 'name'
                ,scope: this
                ,hidden: true
                ,handler: this.onTotggleSearchIn
            },{
                text: L.Content
                ,xtype: 'menucheckitem'
                ,checked: true
                ,searchIn: 'content'
                ,hidden: true
                ,scope: this
                ,handler: this.onTotggleSearchIn
            },{
                text: L.Advanced
                ,disabled: true
                ,hidden: true
                ,scope: this
                // ,handler: this.onAdvancedClick
            }//,'-'

            ];

            //add search templates
            var templates = CB.DB.templates.query('type', 'search');

            templates.each(
                function(t){
                    menuItems.push({
                        iconCls: t.data.iconCls
                        ,data: {template_id: t.data.id}
                        ,text: t.data.title
                        ,scope: this
                        ,handler: this.onSearchTemplateButtonClick
                    });
                }
                ,this
            );

            this.optionsMenu = new Ext.menu.Menu({items: menuItems});
        }
        var position = e.getXY();
        position[0] = position[0] + 291; //Align at bottom right
        position[1] = position[1] + 32; //Align at bottom right
        this.optionsMenu.showAt(position);
    }
    
 	,onSearchTemplateButtonClick: function(b, e) {
        //load default search template if not already loaded
        var config = {
                xtype: 'CBSearchEditWindow'
                ,id: 'sew' + b.config.data.template_id
            };

        config.data = Ext.apply({}, b.config.data);

        var w  = App.windowManager.openWindow(config);
        if(!w.existing) {
        	w.show(); //Changing to show for now, but may want to move it back or something
            //w.alignTo(App.mainViewPort.getEl(), 'bl-bl?');
        }

        delete w.existing;
    }

    ,clear: function(){
        this.setValue('');
        this.triggers.clear.hide();
    }
});
