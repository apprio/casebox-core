
Ext.namespace('CB');

Ext.define('CB.VerticalEditGrid', {
    extend: 'Ext.grid.GridPanel'
    ,alias: [
        'CBVerticalEditGrid'
        ,'widget.CBVerticalEditGrid'
    ]
    ,api: 'CB_Objects.getPluginsData'
    ,border: false
    ,root: 'data'
    ,cls: 'spacy-rows edit-grid'
    ,scrollable: true
    ,autoHeight: true
    ,plugins: []
    //,hideHeaders: true
    ,initComponent: function() {

        // define helperTree if owner does not have already defined one
        var parentWindow = this.getBubbleTarget();
        if(parentWindow.helperTree) {
            this.helperTree = parentWindow.helperTree;
        } else {
            this.helperTree = new CB.VerticalEditGridHelperTree();
        }

        this.initRenderers();
        this.initColumns();

        var viewCfg = {
            autoFill: false
            ,deferInitialRefresh: false
            ,stripeRows: true
            ,markDirty: false
            //,hideHeaders: true
            ,getRowClass: function( record, index, rowParams, store ){
                var rez = '';
                if(record.get('type') === 'H'){
                    rez = 'group-titles-colbg';
                    var node = this.grid.helperTree.getNode(record.get('id'));
                    if(node && !Ext.isEmpty(node.data.templateRecord.get('cfg').css)){
                        rez += ' ' + node.data.templateRecord.get('cfg').css;
                    }
                }
                return rez;
            }
            ,plugins: []

        };
        if(this.viewConfig) {
            Ext.apply(viewCfg, this.viewConfig);
        }

        var plugins = Ext.apply([], Ext.valueFrom(this.plugins, []));
        plugins.push(
            {
                ptype: 'cellediting'
                ,clicksToEdit: 1
                ,listeners: {
                    scope: this
                    ,beforeedit: this.onBeforeEditProperty
                    ,edit: this.onAfterEditProperty
                }
                ,onSpecialKey: this.onCellEditingSpecialKey
            }
        );

        Ext.apply(this, {
            store:  new Ext.data.JsonStore({
                model: 'EditGridRecord'
                ,proxy: {
                    type: 'memory'
                    ,reader: {
                        type: 'json'
                        ,idProperty: 'id'
                        ,messageProperty: 'msg'
                    }
                }
                ,listeners: {
                    scope: this
                    ,update: function(store, record, operation) {
                        if(operation != Ext.data.Record.EDIT) {
                            return;
                        }
                        var node = this.helperTree.getNode(record.get('id'));
                        node.data.value['value'] = record.get('value');
                        node.data.value['info'] = record.get('info');
                        node.data.value['cond'] = record.get('cond');
                    }
                }
            })
            ,columns: Ext.apply([], this.gridColumns) //leave default column definitions intact
            ,selType: 'cellmodel'
            ,header: false
            ,listeners: {
                scope: this
                ,keypress:  function(e){
                    if( (e.getKey() == e.ENTER) && (!e.hasModifier())) {
                        this.onFieldTitleDblClick();
                    }
                }
                ,cellkeydown: function(cell, td, cellIndex, record, tr, rowIndex, e, eOpts ) {
                    if (e.getKey() == e.TAB)
                    {
                        var pos = this.gainFocus((e.shiftKey)? 'previous' : 'next');

                        if(pos) {
                            e.stopEvent();

                            cell.editingPlugin.startEditByPosition({
                                row: pos
                                ,column: 1
                            });
                        }
                    }
                 }
                ,celldblclick:  this.onFieldTitleDblClick
                ,cellclick:  this.onCellClick
            }
            ,stateful: true
            ,stateId: Ext.valueFrom(this.stateId, 'veg')//vertical edit grid
            ,stateEvents: [
                'columnhide'
                ,'columnmove'
                ,'columnresize'
                ,'columnschanged'
                ,'columnshow'
            ]
            ,viewConfig: viewCfg
            ,editors: {
                iconcombo: function(){
                    return new Ext.form.ComboBox({
                        editable: true
                        ,name: 'iconCls'
                        ,hiddenName: 'iconCls'
                        ,tpl: '<tpl for="."><div class="x-boundlist-item icon-padding16 {name}">{name}</div></tpl>'
                        ,store: CB.DB.templatesIconSet
                        ,valueField: 'name'
                        ,displayField: 'name'
                        ,iconClsField: 'name'
                        ,triggerAction: 'all'
                        ,queryMode: 'local'
                    });
                }
            }

            ,plugins: plugins
        });


        this.enableBubble(['change', 'fileupload', 'filedownload', 'filesdelete', 'loaded', 'saveobject']);
        this.callParent(arguments);
    }

    ,initRenderers: function () {
        this.renderers = {
            iconcombo: App.customRenderers.iconcombo

            ,H: function(){ return '';}

            ,title: function(v, meta, record, row_idx, col_idx, store){
                var id = record.get('id');
                var n = this.helperTree.getNode(id);

                if(Ext.isString(v)) {
                    v = Ext.util.Format.htmlEncode(v);
                }

                // temporary workaround for not found nodes
                if(!n) {
                    return v;
                }

                var tr = n.data.templateRecord;

                if(tr.get('type') === 'H'){
                    meta.css ='vgh';
                    if (tr.get('cfg').style) {
                        meta.tdStyle = tr.get('cfg').style;
                    }

                } else {
                    meta.css = 'bgcLG vaT';
                    meta.style = 'margin-left: ' + (n.getDepth()-1) + '0px';
                    if(tr.get('cfg').readOnly === true) {
                        meta.css += ' cG';
                    }

                    if(tr.get('cfg').required && Ext.isEmpty(record.data.value)) {
                        meta.css += ' cRequired';
                        v += ' *';
                    } else if(tr.get('cfg').validationRe) {
                        var regEx = new RegExp(tr.get('cfg').validationRe);
                        if (!regEx.test(record.data.value))
                        {
                            meta.css += ' cRequired';
                            v += ' *';
                        }
                    }

                }

                if(!Ext.isEmpty(tr.get('cfg').hint)) {
                    meta.tdAttr = ' title="'+tr.get('cfg').hint+'"';
                }

                /* setting icon for duplicate fields /**/
                if(this.helperTree.isDuplicate(id)){
                    //show duplicate index
                    // if last (and not exsceeded) then show + icon
                    if(this.helperTree.canDuplicate(id) && this.helperTree.isLastDuplicate(id)) {
                        v = '<img alt="plus sign" name="add_duplicate" title="'+L.addDuplicateField+'" class="fr duplicate-plus" src="'+Ext.BLANK_IMAGE_URL + '" / >' + v;
                    } else {
                        var idx = this.helperTree.getDuplicateIndex(id) +1;
                        v = '<img alt="duplicate" title="' + L.duplicate + ' ' + idx +
                            '" class="fr vc' + idx + '" src="' + Ext.BLANK_IMAGE_URL + '" / >' + v;
                    }
                }

                return v;
            }

            ,value: function(v, meta, record, row_idx, col_idx, store){
                var n = this.helperTree.getNode(record.get('id'));

                if(Ext.isString(v)
                    // && (Ext.util.Format.stripTags(v) !== v)
                ) {
                    v = Ext.util.Format.htmlEncode(v);
                }

                // temporary workaround for not found nodes
                if(!n) {
                    return v;
                }
                var tr = n.data.templateRecord;

                if ((tr.get('type') == 'H') && tr.get('cfg').style) {
                    meta.tdStyle = tr.get('cfg').style;
                }

                //check validation field
                if (record.get('valid') === false) {
                    meta.css = ' x-form-invalid-field-default';
                    //meta.tdAttr = 'data-errorqtip="<ul class=\'x-list-plain\'><li>' + Ext.form.field.Base.prototype.invalidText + '</li></ul>"';
                } else {
                    //Check required field
                    if(tr.get('cfg').required && Ext.isEmpty(v)) {
                        meta.css = ' x-form-invalid-field-default';
                        //meta.tdAttr = 'data-errorqtip="<ul class=\'x-list-plain\'><li>' + Ext.form.TextField.prototype.blankText + '</li></ul>"';
                    } else {
                        // Value is valid
                        meta.css = '';
                        meta.tdAttr = 'data-errorqtip=""';
                    }
                }

                if(this.renderers && this.renderers[tr.get('type')]) {
                    return this.renderers[tr.get('type')](v, this);
                }
                if(!Ext.isEmpty(tr.get('cfg').height)) {
                    meta.style += 'min-height:' + tr.get('cfg').height + 'px';
                }

                var renderer = App.getCustomRenderer(tr.get('type'));

                if(Ext.isEmpty(renderer)) {
                    return v;
                }

                //set field config into meta so that renderers could access necesary params
                meta.fieldConfig = tr.get('cfg');

                return renderer(v, meta, record, row_idx, col_idx, store, this);
            }

        };
    }

    ,initColumns: function() {
        this.gridColumns = [
            {
                header: L.Property
                ,sortable: false
                ,dataIndex: 'title'
                ,stateId: 'title'
                ,editable: false
                ,hideable: false
                ,scope: this
                ,size: 250
                ,width: 250
                ,renderer: this.renderers.title
            },{
                header: L.Value
                ,itemId: 'value'
                ,sortable: false
                ,dataIndex: 'value'
                ,stateId: 'value'
                ,hideable: false
                ,editor: new Ext.form.TextField()
                ,scope: this
                ,flex: 1
                ,resizable: true
                ,renderer: this.renderers.value
            },{
                header: L.Additionally
                ,sortable: false
                ,dataIndex: 'info'
                ,stateId: 'info'
                ,editor: new Ext.form.TextField()
                ,size: 200
                ,hideable: false
            }
        ];
    }

    ,onNodeDragOver: function (targetEl, source, e, data){
        var rez = source.dropNotAllowed;
        var record = this.view.getRecord(targetEl);
        var recs = data.records;

        if(Ext.isEmpty(record) ||
            Ext.isEmpty(recs) ||
            isNaN(Ext.Number.from(recs[0].data.nid, recs[0].data.id))
        ) {
            return rez;
        }

        rez = (record.get('type') === '_objects')
            ? source.dropAllowed
            : source.dropNotAllowed;

        return rez;
    }

    ,onNodeDrop: function(targetEl, source, e, sourceData){
        if(this.onNodeDragOver(targetEl, source, e, sourceData) == source.dropAllowed){
            var record = this.view.getRecord(targetEl)
                ,recs = sourceData.records;
            if(record) {
                var bt = this.view.grid.getBubbleTarget();
                var node = this.helperTree.getNode(record.get('id'));
                var tr = node.data.templateRecord;
                var oldValue = node.data.value.value;
                var v = toNumericArray(oldValue);

                var id, idx = null;

                for (var i = 0; i < recs.length; i++) {
                    id = Ext.Number.from(recs[i].data.nid, recs[i].data.id);
                    idx = v.indexOf(id);
                    if(idx >= 0) {
                        v.splice(idx, 1);
                    } else {
                        v.push(id);
                        if(bt.objectsStore) {
                            bt.objectsStore.checkRecordExistance(recs[i].data);
                        }
                    }
                }
                var newValue = v.join(',');

                record.set('value', newValue);
                this.fireEvent('change', tr.get('name'), newValue, oldValue);
            }
            return true;
        }
        return false;
    }

    ,onCellClick: function( g, td, cellIndex, record, tr, rowIndex, e, eOpts){//g, r, c, e
        var el = e.getTarget();
        if(el) {
            switch(el.name){
                case 'add_duplicate':
                    this.onDuplicateFieldClick();
                    break;
            }
        }
    }

    ,onPopupMenu: function(gridView, el, colIndex, record, rowEl, rowIndex, ev, eOpts){
        ev.preventDefault();
        switch(this.columns[colIndex].dataIndex){
            case 'title':
                this.showTitlePopupMenu(this, rowIndex, colIndex, ev);
                break;
        }
    }

    ,showTitlePopupMenu: function(grid, rowIndex, cellIndex, e){
        var r = grid.getStore().getAt(rowIndex);
        this.popupForRow = rowIndex;

        if(!this.titlePopupMenu) {
            this.titlePopupMenu = new Ext.menu.Menu({
                items: [
                    {
                        text: L.addDuplicateField
                        ,scope: this
                        ,handler: this.onDuplicateFieldClick
                    },{
                        text: L.delDuplicateField
                        ,scope: this
                        ,handler: this.onDeleteDuplicateFieldClick
                    }
                ]
            });
        }
        this.titlePopupMenu.items.getAt(0).setDisabled(!this.helperTree.canDuplicate(r.get('id')));
        this.titlePopupMenu.items.getAt(1).setDisabled(this.helperTree.isFirstDuplicate(r.get('id')));
        this.titlePopupMenu.showAt(e.getXY());
    }

    ,onFieldTitleDblClick: function(gridView, td, cellIndex, record, tr, rowIndex, e, eOpts){
        var sm = this.getSelectionModel();

        var fieldName = this.columns[cellIndex].dataIndex;

        if(fieldName === 'title'){
            this.editingPlugin.startEdit(record, 1);//begin field edit
        }
    }

    ,getBubbleTarget: function(){
        if(!this.parentWindow){
            this.parentWindow = this.findParentByType('CBGenericForm') || this.refOwner;
        }
        return this.parentWindow;
    }

    ,reload: function(){
        // initialization
        this.data = {};
        this.newItem = true;
        var pw = this.getBubbleTarget(); //parent window

        if(Ext.isDefined(pw.data)) {
            this.newItem = isNaN(pw.data.id);
            if(Ext.isDefined(pw.data[this.root])) {
                this.data = pw.data[this.root];
            }
        }
        if (this.parentWindow.data.type !== 'task' && this.parentWindow.data.type !== 'case' && this.parentWindow.data.type !== 'object' && this.parentWindow.data.type !== 'menu') {
          CB_Objects.getPluginsData({id: this.parentWindow.data.pid}, this.processLoadPreviewData, this);
        }
        //if not specified template_id directly to grid then try to look in owners data
        this.template_id = Ext.valueFrom(pw.data.template_id, this.template_id);
        if(isNaN(this.template_id)) {
            return Ext.Msg.alert('Error', 'No template id specified in data for "' + pw.title + '" window.');
        }
        this.template_id = parseInt(this.template_id, 10);

        this.templateStore = Ext.clone(CB.DB['template' + this.template_id]);

        var idx = CB.DB.templates.findExact('id', this.template_id);
        if(idx >= 0) {
            // var cm = this.getColumnModel();
            var tc = CB.DB.templates.getAt(idx).get('cfg');//template config

            var infoCol = this.headerCt.child('[dataIndex="info"]'); //cm.findColumnIndex('info');
            var colRequired = (
                (tc.infoColumn === true) ||
                (
                    (Ext.isEmpty(infoCol)) &&
                    (!Ext.isEmpty(App.config.template_info_column))
                )
            );

            var newConfig = Ext.apply([], this.gridColumns);

            if(!Ext.isEmpty(infoCol) &&  !colRequired) {
                if(!colRequired) {
                    newConfig.pop();
                }

                //apply state to columns
                if(this.stateful) {
                    var state = Ext.state.Manager.get(this.stateId);
                    if(state && state.columns) {
                        Ext.iterate(
                            newConfig,
                            function(c) {
                                if(state.columns[c.dataIndex]) {
                                    Ext.apply(c, state.columns[c.dataIndex]);
                                }
                            }
                            ,this
                        );
                    }
                }

                this.reconfigure(this.store, newConfig);
            }
        }
        // if parent have a helperTree then it is responsible for helper reload
        if(!pw.helperTree) {
            this.helperTree.newItem = this.newItem;
            this.helperTree.loadData(this.data, this.templateStore);
        }

        this.syncRecordsWithHelper();

        this.fireEvent('loaded', this);
    }

    ,processLoadPreviewData: function(r, e) {
        if(!r || (r.success !== true)) {
            return;
        }

        var objProperties  = Ext.valueFrom(r.data.objectProperties, {}).data;

        this.data = Ext.apply(Ext.valueFrom(this.data, {}), objProperties);
        this.data.from = 'window';
      }

    ,syncRecordsWithHelper: function(){
        if(!this.store) {
            return;
        }

        var nodesList = this.helperTree.queryNodeListBy(this.helperNodesFilter.bind(this))
            ,ids = this.store.collect('id')
            ,update = false
            ,i, idx, id, r;

        //check if store records should be updated
        for (i = 0; i < nodesList.length; i++) {
            id = nodesList[i].data.id;
            idx = ids.indexOf(id);
            if(idx < 0) {
                update = true;
            } else {
                ids.splice(idx, 1);
                //check if value not reset
                r = this.store.getById(id);
                if(r && nodesList[i].data.value.value !== r.data.value) {
                    r.data.value = nodesList[i].data.value.value;
                }
            }
        }

        if(!update && Ext.isEmpty(ids)) {
            return;
        }

        if(this.store && this.store.suspendEvents) {
            this.store.suspendEvents(true);
        }

        this.store.removeAll(false);

        var records = [];
        for (i = 0; i < nodesList.length; i++) {
            var attr = nodesList[i].data;
            r  = attr.templateRecord;

            records.push(
                Ext.create(
                    this.store.getModel().getName()
                    ,{
                        id: attr.id
                        ,title: r.get('title')
                        ,readonly: ((r.get('type') === 'H') || (r.get('cfg').readOnly == 1))
                        ,value: (Ext.isNumeric(attr.value.value) && (r.get('type') !== 'varchar'))
                            ? parseFloat(attr.value.value, 10)
                            : attr.value.value
                        ,info: attr.value.info
                        ,type: r.get('type')
                        ,cond: attr.value.cond
                        ,valid: attr.valid
                    }
                )
            );
        }
        this.store.resumeEvents();
        this.store.add(records);

        return true;
    }

    ,helperNodesFilter: function(node){
        var r = node.data.templateRecord;
        //skip check for root node
        if(Ext.isEmpty(r)) {
            return false;
        }

        if (Ext.isArray(this.hideTemplateFields)) {
            var a = this.hideTemplateFields;
            if ((a.indexOf(r.get('name')) > -1) ||
                (a.indexOf(r.get('id')) > -1) ||
                (a.indexOf(r.get('id') + '') > -1)
            ) {
                return false;
            }
        }

        return (
            (r.get('type') !== 'G')
            &&
            (
                (r.get('cfg').showIn !== 'top') ||
                ((r.get('cfg').showIn === 'top') &&
                    this.includeTopFields
                )
            ) &&
            (r.get('cfg').showIn !== 'tabsheet') &&
            (node.data.visible !== false)
        );
    }

    ,readValues: function(){
        if(!Ext.isDefined(this.data)) {
            this.data = {};
        }

        this.data = this.helperTree.readValues();

        var w = this.getBubbleTarget();
        if(Ext.isDefined(w.data)) {
            w.data[this.root] = this.data;
        }
    }

    ,onBeforeEditProperty: function(editor, context, eOpts){//grid, record, field, value, row, column, cancel
        var node = this.helperTree.getNode(context.record.get('id'));
        // temporary workaround for not found nodes
        if(!node) {
            return false;
        }
        //New Comment
        var tr = node.data.templateRecord;
        if((tr.get('type') === 'H') || (tr.get('cfg').readOnly == 1) ){
            return false;
        }
        if(context.field !== 'value') {
            return;
        }

        delete context.grid.pressedSpecialKey;

       //iterating over the store to see if the 'task_status' variable is there.
        //If it is, and the value is Open[1906], then make Time Expended not required; else required.

        var open;
        for(var i = 0; i < this.store.data.length; i++){
            var curr = this.store.getAt(i);
            if(curr.data.title == "Status"){
                if(this.store.getAt(i).data.value == 1906){//open
                    open = true;
                } else if(this.store.getAt(i).data.value == 1907){//closed
                    open = false;
                }
                break;
            }
        }
       var r = null;
        for(var i = 0; i < this.store.data.length; i++){
            if(this.store.getAt(i).data.title == "Time Expended"){
                r = this.store.getAt(i);
                break;
            }
        }
        if(r){
            var n = this.helperTree.getNode(r.get('id'));
            n.data.templateRecord.get('cfg').required = !open;
        }

        var pw = this.findParentByType(CB.GenericForm, false)
            || this.refOwner
        ; //CB.Objects & CB.TemplateEditWindow
        var t = tr.get('type');
        if(pw && !Ext.isEmpty(pw.data)){
            context.objectId = pw.data.id;
            context.objectPid = pw.data.pid;
            context.path = pw.data.path;
            context.fematier = pw.data.fematier;
            if (!Ext.isEmpty(pw.data.survivorName)) {
              context.survivorName = pw.data.survivorName;
            } else {
              context.survivorName = pw.data.data.name;
            }
        }

        // If survivor's status is Information Only, set required fields to false
        if (tr.data.name == '_linkedsurvivor' || tr.data.name == '_linkedsurvivorname') {
          if(pw.data.infoOnly == 1577){
        		var infoOnly = true;

	            var reqEntryTitles = ["Assessment Date", "Referral Needed?", "Is disaster survivor or anyone in the household in distress?", "Would disaster survivor or anyone in the household like to speak to someone about coping with disaster-related stress?",
	            					"Is the disaster survivor caring for a foster child(ren)?", "Prior to the disaster, was the disaster survivor's child in a Head Start Program?", "Prior to the disaster, was the disaster survivor's child in childcare?",
	            					"Does disaster survivor currently have a need for child care?", "Prior to the disaster, did disaster survivor get voucher assistance for child care?", "Was disaster survivor receiving child support payments before the disaster?",
	            					"Are the disaster survivor's children currently in school?", "Has your child missed any scheduled checkups or immunizations since the disaster?", "Does disaster survivor have concerns about how his/her child is coping post-disaster?",
	            					"Did any of the household members lose clothing as a result of the disaster?", "Does disaster survivor/family have useable clothing and shoes for work or school?", "Does disaster survivor/family have cold-weather clothing (e.g.,coats, hats, gloves)?",
	            					"Does disaster survivor have a FEMA registration number?", "Disaster Survivor has submitted SBA application", "Disaster Survivor has submitted claim for FEMA Individual Assistance", "Disaster Survivor has received Non-Comp Notice from FEMA IA",
	            					"Disaster Survivor has received FEMA IA Benefit", "Disaster Survivor has received MAX Grant from FEMA", "Disaster Survivor has applied for FEMA Other Needs Assistance", "Disaster Survivor has received ONA", "Disaster Survivor was denied for ONA",
	            					"Does disaster survivor have enough food to feed all members of the household?", "Pre-Disaster, was disaster survivor or any household member receiving food assistance?", "Since the disaster, has disaster survivor requested help with food from anyone?",
	            					"Did disaster survivor have furniture or home appliances destroyed in the disaster?", "Do you have Health Insurance?", "Was this insurance lost as a result of the disaster?", "Where did the disaster survivor live pre-disaster?", "In the disaster, was disaster survivor home damaged or affected?",
	            					"Is the disaster survivor able to access the home?", "Does disaster survivor consider home livable or inhabitable?", "Disaster Survivor Damage Rating", "Was disaster survivor relocated/evacuated?", "Do all of disaster survivor's utilities work?",
	            					"Details of Disaster Impacts to Home", "Pre-disaster housing insurance status", "Pre-Disaster, was disaster survivor receiving language services?", "Is disaster survivor currently having difficulty accessing services due to language concerns?",
	            					"As a result of the disaster, disaster survivor lost language services?", "Prior to the disaster, was anyone in the household living in senior housing, assisted living, or in a nursing home?", "Applicant has a move out date", "Move In Date", "Owner/Renter",
	            					"Pre Disaster HUD Housing such as Section 8, subsidized housing, etc.", "Pre Disaster Homeless", "Identified Available Rental", "Plan to return to Pre-Disaster Residence", "Will Live with Family or Friends", "Waiting on DD (Damaged Dwelling) to become accessible",
	            					"Inaccessible due to Road Closure", "Inaccessible due to Water Receding", "Identified available housing or hotel resource but not within reasonable commuting distance ", "Cannot find affordable housing resource", "Cannot find short term lease ", "Transportation issues -- Transportation disaster damage, need assistance for repairs",
	            					"No desire to relocate out of state within 50 - 100 mile radius", "Need specialized medical equipment (sensory, mobility, accessibility, etc.)", "Transportation issues -- cannot meet with inspector", "Transportation issues -- cannot get to desired area to look for housing",
	            					"Need funds to move household belongings", "Utilities not currently operable", "Electricity not currently operable", "Nowhere for my pet to board", "Pet Type", "Need voluntary agencies to assist with mucking out home", "# Children (Under 18)", "Consent to Share", "Number of Adults (18+)",
	            					"Notes", "What was the disaster survivor's primary mode of transportation prior to the disaster?", "Employed?", "Did you lose your job because of the disaster?", "Looking for additional employment/increased hours?",
                        "Prior to the disaster, was the Disaster Survivor's child in a Head Start Program?", "Prior to the disaster, was the Disaster Survivor's child in childcare?", "Are the Disaster Survivor's children currently attending school?", "Has Disaster Survivor applied for Disaster Unemployment Assistance?",
                        "Income Received?", "Income Group", "Earned income (i.e. employment income)", "Unemployment Insurance", "Supplemental Security Income (SSI)", "Social Security Disability Income (SSDI)", "Veterans Disability Payment", "Rent", "Mortgage", "Maintenance", "Car Payment", "Car Insurance", "Gasoline",
                        "Medical", "Food", "Miscellaneous", "Number of Expenses", "What was the Disaster Survivor's primary mode of transportation prior to the disaster?", "Evaluation Date", "Family Size", "Annual Household Income Range", "Pre-Disaster, was Disaster Survivor or any household member receiving any of the following?"
                        , "Estimated Annual Household Income Range", "Post-Disaster, is Disaster Survivor or any household member receiving any of the following?", "Disaster Unemployment Assistance received?"];

	            for(var i = 0; i < reqEntryTitles.length; i++){
	                var curr = reqEntryTitles[i];
	                for(var j = 0; j < this.store.data.length; j++){
	                    var r = this.store.getAt(j);
	                    if(curr == r.data.title){
	                        var n = this.helperTree.getNode(r.get('id'));
	                        n.data.templateRecord.get('cfg').required = !infoOnly;
	                	  }
	            	}
        		}
        	}
        }

        if (tr.data.name == '_fematier') {
          if (context.fematier) {
            for(var j = 0; j < this.store.data.length; j++){
                var r = this.store.getAt(j);
                if(r.data.title === "Optional, Change Disaster Survivor's Current FEMA Tier?"){
                    var fematier;
                    switch (context.fematier) {
                      case 1325:
                        fematier = 'Tier 1 - Immediate Needs Met';
                        break;
                      case 1326:
                        fematier = 'Tier 2 - Some Remaining Unmet Needs or in Current Rebuild/Repair Status';
                        break;
                      case 1327:
                        fematier = 'Tier 3 - Significant Unmet Needs';
                        break;
                      case 1328:
                        fematier = 'Tier 4 - Immediate and Long-Term Unmet Needs';
                        break;
                    }
                    Ext.Msg.alert('The current FEMA Tier of this survivor is: ', fematier);
                }
            }
          }
        }

        if (tr.data.name == '_linkedsurvivor') {
        	if (context.objectPid) {
        		tr.data.cfg.value = context.objectPid;
        		context.value = context.objectPid;
            node.data.value.value = context.objectPid;
        	}
        }

        if (tr.data.name == '_linkedsurvivorname') {
        	if (context.objectPid) {
        		tr.data.cfg.value = context.survivorName;
        		context.value = context.survivorName;
            node.data.value.value = context.survivorName;
        	}
        }

        /* get and set pidValue if dependent */
        if( (Ext.isDefined(tr.get('cfg').dependency) ) && !Ext.isEmpty(tr.get('pid')) ) {
                context.pidValue = this.helperTree.getParentValue(context.record.get('id'), tr.get('pid'));
        }

        /* prepare time fields */
        if((t === 'time') && !Ext.isEmpty(context.value)) {
            var a = context.value.split(':');
            a.pop();
            context.value = a.join(':');
        }

        var col = context.column
            ,previousEditor = col.getEditor()
            ,recordId = context.record.get('id');


        if(this.editors && this.editors[t]) {
            col.setEditor(this.editors[t](this));
        } else {
            context.fieldRecord = this.helperTree.getNode(recordId).data.templateRecord;
            context.duplicationIndexes = this.helperTree.getDuplicationIndexes(recordId);

            //check if custom source and send fields
            if(Ext.isObject(context.fieldRecord.get('cfg')['source'])) {
                var fields = context.fieldRecord.get('cfg')['source'].requiredFields;
                if(!Ext.isEmpty(fields)) {
                    if(!Ext.isArray(fields)) {
                        fields = fields.split(',');
                    }
                    context.objFields = {};
                    var currentData = this.helperTree.readValues();

                    for (var i = 0; i < fields.length; i++) {
                        var f = fields[i].trim();

                        if(!Ext.isEmpty(currentData[f])) {
                            context.objFields[f] = currentData[f];
                        }
                    }
                }
            }

            var te = App.getTypeEditor(t, context);

            this.attachKeyListeners(te);
            if(te) {
                if (Ext.isDefined(tr.get('cfg').maskRe))
                {
                    te.maskRe = new RegExp(tr.get('cfg').maskRe);
                }
                if (Ext.isDefined(tr.get('cfg').emptyText))
                {
                    te.emptyText = tr.get('cfg').emptyText;
                }
                col.setEditor(te);
            }
        }

        // destroy previous editor if changed
        var currentEditor = col.getEditor();
        if(previousEditor && (previousEditor != currentEditor)) {
            Ext.destroy(previousEditor);
        }
    }

    ,gainFocus: function(position){
        var sm = this.getSelectionModel()
            ,navModel = this.getNavigationModel()
            ,lastFocused = navModel.getLastFocused()
            ,rez = Ext.clone(lastFocused);


        if(lastFocused && !isNaN(lastFocused.rowIdx)){
            if(position === 'next') {
                if(lastFocused.colIdx < (this.visibleColumnManager.columns.length-1)) {
                    rez.colIdx++;
                } else {
                    if(lastFocused.rowIdx < (this.store.getCount() -1)) {
                        rez.rowIdx++;
                        var fieldType = this.store.getAt(rez.rowIdx).get('type');
                        if (fieldType === 'H')
                        {
                            rez.rowIdx++;
                        }
                        rez.colIdx = 1;
                    } else {
                        rez = null;
                    }
                }
            }else if(position === 'previous') {
                  if(rez.rowIdx > 1) {
                    rez.rowIdx--;
                    var fieldType = this.store.getAt(rez.rowIdx).get('type');
                    if (fieldType === 'H' && rez.rowIdx > 2)
                    {
                        rez.rowIdx--;
                    }
                        rez.colIdx = 1;
                    } else {
                     rez = null;
                    }
                 }
            var cell = Ext.isEmpty(rez)
                ? lastFocused
                : rez;

            sm.select({
                row: cell.rowIdx
                ,column: cell.colIdx
            });

            navModel.setPosition(cell.rowIdx, cell.colIdx);

            navModel.focusPosition(cell);
        }
        else
        {
            if(position === 'next') {
                sm.select({row: this.store.getCount() -1, column: 1});
                navModel.setPosition(this.store.getCount()-1, 1);
            }else if(position === 'previous') {
                sm.select({row: 0, column: 1});
                navModel.setPosition(0, 1);
            }
        }

        return rez;
    }

    ,addKeyMaps: function(c) {
        var map = new Ext.KeyMap(c.getEl(), [
            {
                key: "s"
                ,ctrl: true
                ,shift: false
                ,scope: this
                ,stopEvent: true
                ,fn: this.onSaveObjectEvent
            }
        ]);
    }

    ,attachKeyListeners: function(comp) {
        if(Ext.isEmpty(comp) || !Ext.isObject(comp)) {
            return;
        }
        comp.on(
            'afterrender'
            ,this.addKeyMaps
            ,this
        );
    }

    ,onCellEditingSpecialKey: function(ed, field, e) {
        var key = e.getKey();
        switch(key) {
            //case e.ENTER:
            case e.TAB:
            ed.grid.pressedSpecialKey = key;
                ed.completeEdit();

                var pos = ed.grid.gainFocus((e.shiftKey)? 'previous' : 'next');

                if(pos) {
                    e.stopEvent();

                    this.startEditByPosition({
                        row: pos.rowIdx
                        ,column: pos.colIdx
                    });
                }
                break;

            case e.ESC:
                ed.grid.pressedSpecialKey = key;
                break;
        }

    }

    ,onSaveObjectEvent: function (key, event){
        if(this.editingPlugin.editing) {
            this.editingPlugin.completeEdit();
        }

        this.fireEvent('saveobject', this, event);
    }

    ,onAfterEditProperty: function(editor, context, eOpts){
        var nodeId = context.record.get('id')
            ,node = this.helperTree.getNode(nodeId)
            ,tr = node.data.templateRecord;

        if (tr.data.title == "Optional, Change Disaster Survivor's Current FEMA Tier?") {
          if (tr.data.cfg.value != context.value) {
            for(var j = 0; j < this.store.data.length; j++){
                var r = this.store.getAt(j);
                if(r.data.title === "FEMA Tier Change Note"){
                    var n = this.helperTree.getNode(r.get('id'));
                    n.data.templateRecord.get('cfg').required = true;
                }
            }
          } else {
            for(var j = 0; j < this.store.data.length; j++){
                var r = this.store.getAt(j);
                if(r.data.title === "FEMA Tier Change Note"){
                    var n = this.helperTree.getNode(r.get('id'));
                    n.data.templateRecord.get('cfg').required = false;
                }
            }
          }
        }

        if (tr.data.name == '_phonenumber') {
          tr.data.cfg.validationRe = "^(\\([0-9]{3}\\)\\s*|[0-9]{3}\\-)[0-9]{3}-[0-9]{4}$";
        }

        if (tr.data.name == 'case') {
        		var caseId = context.value;
            var r = this.store.getAt(1);

            var n = this.helperTree.getNode(r.get('id'));
            n.data.templateRecord.get('cfg').value = caseId;
            n.data.value.value = caseId;
        }

        //When the Status of this task is switched to Closed, Time Expended becomes required.
        if(tr.data.name == "task_status"){
            var open;
            if(context.value == 1907){
                open = false;
            } else if(context.value == 1906){
                open = true;
            }

            var r;
            for(var i = 0; i < this.store.data.length; i++){
            if(this.store.getAt(i).data.title == "Time Expended"){
                    r = this.store.getAt(i);
                    break;
                }
            }
            var n = this.helperTree.getNode(r.get('id'));
            n.data.templateRecord.get('cfg').required = !open;
        }

        // Max character count
        if (tr.data.name == '_casenote' || tr.data.name == '_task' || tr.data.name == 'description') {
        	if (tr.data.cfg.validationRe) {
        		var maxChar = parseInt(tr.data.cfg.validationRe.match(/\d/g).join(''), 10);
        		if (context.value.length > maxChar) {
        			Ext.Msg.alert('Exceeded Character Limit', 'The maximum character limit is' + ' ' + maxChar);
        		}
        	}
        }

        if(tr.data.name == "_clientstatus"){
            var infoOnly;
            if(context.value == 1578){
                infoOnly = false;
            } else if(context.value == 1577){
                infoOnly = true;
            }
            var reqEntryTitles = ["First Name", "Last Name", "Best Phone Number", "Self-Reported Special/At-Risk Populations", "Self-Identified Unmet Needs", "FEMA Tier", "FEMA Registration Number", "Does disaster survivor have a FEMA registration number?", "Current Facility"];

            for(var i = 0; i < reqEntryTitles.length; i++){
                var curr = reqEntryTitles[i];
                for(var j = 0; j < this.store.data.length; j++){
                    var r = this.store.getAt(j);
                    if(curr == r.data.title){
                      if (r.data.title == 'Best Phone Number'){
                        var n = this.helperTree.getNode(r.get('id'));
                        if (infoOnly == true) {
                          n.data.templateRecord.get('cfg').required = false;
                          n.data.templateRecord.get('cfg').validationRe = "";
                        } else {
                          n.data.templateRecord.get('cfg').required = true;
                          n.data.templateRecord.get('cfg').validationRe = "^(\\([0-9]{3}\\)\\s*|[0-9]{3}\\-)[0-9]{3}-[0-9]{4}$";
                        }
                      } else {
                        var n = this.helperTree.getNode(r.get('id'));
                        n.data.templateRecord.get('cfg').required = !infoOnly;
                      }
                    }
                }
            }
      } else {
            var onCorrectPage = false;
            var infoOnly;
            var survivorStatus = this.store.getAt(0);
            if(survivorStatus.get('value') == 1578){
                infoOnly = false;
                onCorrectPage = true;
            } else if(survivorStatus.get('value') == 1577){
                infoOnly = true;
                onCorrectPage = true;
            }
            if(onCorrectPage){
                var reqEntryTitles = ["First Name", "Last Name", "Best Phone Number", "Self-Reported Special/At-Risk Populations", "Self-Identified Unmet Needs", "FEMA Tier", "FEMA Registration Number", "Does disaster survivor have a FEMA registration number?", "Current Facility"];

                for(var i = 0; i < reqEntryTitles.length; i++){
                    var curr = reqEntryTitles[i];
                    for(var j = 0; j < this.store.data.length; j++){
                        var r = this.store.getAt(j);
                        if(curr == r.data.title){
                          if (r.data.title == 'Best Phone Number'){
                            var n = this.helperTree.getNode(r.get('id'));
                            if (infoOnly == true) {
                              n.data.templateRecord.get('cfg').required = false;
                              n.data.templateRecord.get('cfg').validationRe = "";
                            } else {
                              n.data.templateRecord.get('cfg').required = true;
                              n.data.templateRecord.get('cfg').validationRe = "^(\\([0-9]{3}\\)\\s*|[0-9]{3}\\-)[0-9]{3}-[0-9]{4}$";
                            }
                          } else {
                            var n = this.helperTree.getNode(r.get('id'));
                            n.data.templateRecord.get('cfg').required = !infoOnly;
                          }
                        }
                    }
                }
            }
        }
        if(context.field === 'value'){
            /* post process value */
            if(!Ext.isEmpty(context.value) && context.fieldRecord) {
                switch(context.fieldRecord.get('type')) {
                    case 'time':
                        if(Ext.isPrimitive(context.value)) {
                            var format = Ext.valueFrom(tr.get('cfg').format, App.timeFormat);
                            context.value = Ext.Date.parse(context.value, format);
                        }

                        context.value = Ext.Date.format(context.value, 'H:i:s');
                        context.record.set('value', context.value);
                        break;
                    case 'xdate':
                    case 'date':
                        if (tr.get('cfg').generateAge != null) //Check if config "generateAge" is there
                        {
                            var recordIndex = this.store.findExact('title', tr.get('cfg').generateAge);
                            if(recordIndex >= 0)
                            {
                                var today = new Date();
                                var birthDate = new Date(context.value);
                                var age = today.getFullYear() - birthDate.getFullYear();
                                var m = today.getMonth() - birthDate.getMonth();
                                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                                    age--;
                                }
                                this.store.getAt(recordIndex).set('value', age);
                            }
                        }
                        break;
                    case 'float': case 'int':
                        if(tr.get('cfg').totalValue != null) {
                            var recordIndex = this.store.findExact('title', tr.get('cfg').totalValue);
                            if(recordIndex >= 0)
                            {
                                var currentTotal = this.store.getAt(recordIndex).get('value');
                                if (currentTotal == null || isNaN(currentTotal))
                                {
                                    currentTotal = 0;
                                }
                                if (context.originalValue != null && !isNaN(context.originalValue))
                                {
                                    currentTotal = (+currentTotal) - (+context.originalValue);
                                }
                                if (context.value != null && !isNaN(context.value))
                                {
                                    currentTotal = (+currentTotal) + (+context.value);
                                }
                                this.store.getAt(recordIndex).set('value', currentTotal);
                            }
                        }
                        break;

                    case '_objects': // FIRST START FEMA NUMBER CUSTOM CODE
                        if (tr.get('cfg').scope == 1544) //Does disaster survivor have FEMA registration number?
                        {
                             //1547 = DECLINED, 1548 = DOESNT KNOW,1546 = WOULD LIKE TO REGISTER,1549 - DECLINED REGISTER
                             var femaValue = "";
                             var femaRequired = false;
                             switch (context.value) {
                                  case 1547:
                                    femaValue = "DECLINED";
                                    break;
                                  case 1548:
                                    femaValue = "FOLLOWUP";
                                    break;
                                  case 1546:
                                     femaValue = "REGISTER";
                                    break;
                                  case 1549:
                                    femaValue = "DECLINEDREGISTER";
                                    break;
                                  case 247073:
                                    femaValue = "NOTUSCITIZEN";
                                    break;
                                  case 1545:
                                    femaValue = "";
                                    femaRequired = true;
                                }
                            if (femaValue != "") //THEY DON'T HAVE #
                            {
                                if (femaValue == "FOLLOWUP" || femaValue == "REGISTER") //They are going to followup or register
                                {
                                        Ext.Msg.alert(
                                        'Notice',
                                        'You have selected a ' + femaValue.toLowerCase() + ' action and agree to ' + femaValue.toLowerCase() + ' with the client.  If this is not what was meant, please select another option.'
                                        );
                                }
                                else if (femaValue == "NOTUSCITIZEN") //They are going to followup or register
                                {
                                        Ext.Msg.alert(
                                        'Notice',
                                        'You have selected that the person requesting assistance is NOT a U.S. citizen or dependent.  Please speak with an ERC staff before continuing with this form.  If this is not what was meant, please select another option.'
                                        );
                                }
                            }

                            //Checking if the page is the new disaster survivor entry form
                            var recordIndex = -1;
                            for(var i = 0; i < this.store.data.length; i++){
                                if(this.store.getAt(i).data.title == "FEMA Registration Number"){
                                    recordIndex = i;
                                    break;
                                }
                            }
                            if(recordIndex >= 0) //FEMA REGISTRATION NUMBER FIELD
                            {
                                var r = this.store.getAt(recordIndex);
                                var n = this.helperTree.getNode(r.get('id'));
                                n.data.templateRecord.get('cfg').required = femaRequired;
                                n.data.templateRecord.get('cfg').readOnly = !femaRequired;
                                r.set('value', femaValue);
                            }
                        }

                        if(Ext.isArray(context.value)) {
                            context.value = context.value.join(',');
                            context.record.set('value', context.value);
                        }
                        break;
                }
            }

            //check if field has validator set and notify if validation not passed
            var validator = tr.get('cfg').validator;

            if(!Ext.isEmpty(validator)) {
                if(!Ext.isDefined(CB.Validators[validator])) {
                    plog('Undefined field validator: ' + validator);

                } else {
                    //empty values are considered valid by default
                    node.data.valid = Ext.isEmpty(context.value) || CB.Validators[validator](context.value);
                    context.record.set('valid', node.data.valid);
                }
            }

            if (Ext.isDefined(tr.get('cfg').validationRe))
            {
                var regEx = new RegExp(tr.get('cfg').validationRe);
                context.record.set('valid',regEx.test(context.value));
            }

            if(context.value != context.originalValue){
                this.helperTree.resetChildValues(nodeId);
            }

            //check if editor field has getValueRecords (tag field) method and check records existance
            var fe = context.column.field;
            if(fe.getValueRecords) {
                var records = fe.getValueRecords();
                for (var i = 0; i < records.length; i++) {
                    this.refOwner.objectsStore.checkRecordExistance(records[i].data);
                }
            }
        }

        //fire change event if value changed
        if(context.value != context.originalValue) {
            this.fireEvent(
                'change'
                ,tr.get('name')
                ,context.value
                ,context.originalValue
            );
        }

        this.fireEvent('savescroll', this);

        if(!this.syncRecordsWithHelper()) {
            this.getView().refresh();
        } else {
       if (!this.pressedSpecialKey)
       {
           this.gainFocus('next');
       }
            this.fireEvent('restorescroll', this);
        }

        //the grid shouldnt be focused all the time,
        //the user can click outside of the grid
        if(this.pressedSpecialKey) {
            this.gainFocus();
        }
    }

    ,getFieldValue: function(field_id, duplication_id){
        //TODO: review
        var result = null;

        this.store.each(
            function(r){
                if((r.get('field_id') == field_id) && (r.get('duplicate_id') == duplication_id)){
                    result = r.get('value');
                    return false;
                }
            }
            ,this
        );
        return result;
    }

    /**
     * set value for a field
     *
     * TODO: review for duplicated fields
     *
     * @param varchar fieldName
     * @param variant value
     */
    ,setFieldValue: function(fieldName, value) {
        var helperTreeNode = this.helperTree.setFieldValue(fieldName, value);

        if(Ext.isEmpty(helperTreeNode)) {
            return;
        }

        var recordIndex = this.store.findExact('id', helperTreeNode.data.id);

        if(recordIndex >= 0) {
            this.store.getAt(recordIndex).set('value', value);
        }
    }

    ,onDuplicateFieldClick: function(b){
        var r = this.getSelectionModel().getSelection()[0];
        if(Ext.isEmpty(r)) {
            return;
        }

        this.fireEvent('savescroll', this);

        this.helperTree.duplicate(r.get('id'));
        this.syncRecordsWithHelper();

        this.fireEvent('restorescroll', this);

        this.fireEvent('change');
    }

    ,onDeleteDuplicateFieldClick: function(b){
        var r = this.getSelectionModel().getSelection()[0];
        if(Ext.isEmpty(r)) {
            return;
        }

        this.fireEvent('savescroll', this);

        this.helperTree.deleteDuplicate(r.get('id'));
        this.syncRecordsWithHelper();

        this.fireEvent('restorescroll', this);

        this.fireEvent('change');
    }

    /**
     * check if every record meets required config option
     * and is valid if validator set
     * @return bool
     */
    ,isValid: function() {
        var rez = true;
        delete this.invalidRecord;

        this.store.each(
            function(r) {
                var n = this.helperTree.getNode(r.get('id'));
                if((r.get('valid') === false) ||
                    (n.data.templateRecord.get('cfg').required &&
                    Ext.isEmpty(r.get('value'))
                    ) || (n.data.templateRecord.get('cfg').validationRe &&
                   !(new RegExp(n.data.templateRecord.get('cfg').validationRe).test(r.get('value'))))
                ) {
                    this.invalidRecord = r;
                    rez = false;
                }
                return rez;
            }
            ,this
        );

        return rez;
    }

    ,focusInvalidRecord: function() {
        var view = this.getView();

        if (this.invalidRecord) {
            Ext.get(view.getRow(this.invalidRecord)).scrollIntoView(view.getEl(), null, true);

            Ext.Msg.alert(
                L.Error,
                L.FillFieldMsg.replace('{fieldName}', this.invalidRecord.get('title'))
            );
        }
    }
});
