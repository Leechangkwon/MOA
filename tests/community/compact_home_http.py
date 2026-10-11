"""Real router shortcut contract; harness-owned empty synthetic database only."""
assert manifest['database'].startswith('tbox_memories_test_')
shortcut_ids=['spaces','work','tasks','library','story','messages','assistant','account']
shortcut_default=['spaces','work','library','tasks','messages','assistant']
endpoint='/api/me/home-dashboard'
current=call('GET',endpoint);current['layout']=widget_layout();current['layout']['shortcuts']=shortcut_ids
other_before=call('GET',endpoint,user='other')
call('PATCH',endpoint,current,csrf=False,expected=403);call('PATCH',endpoint,current,user=None,expected=401)
current=call('PATCH',endpoint,current);check(current['layout']['shortcuts']==shortcut_ids,'shortcut HTTP ordered allowlist roundtrip')
old=deepcopy(current);del current['layout']['shortcuts'];current['layout']['guide']='done';current=call('PATCH',endpoint,current);check(current['layout']['shortcuts']==shortcut_ids and current['layout']['guide']=='done','HTTP omission preserves shortcut preference and other changes')
call('PATCH',endpoint,old,expected=409)
for bad in [{},None,True,0,1.0,'work',['work','work'],['unknown'],['https://invalid.example'],[1],['work',False],[{'id':'work'}],{'0':'work'},shortcut_ids+['work']]:
 invalid=deepcopy(current);invalid['layout']['shortcuts']=bad;call('PATCH',endpoint,invalid,expected=400)
for version in [1,2]:
 invalid=legacy(deepcopy(current),True) if version==1 else v2(deepcopy(current),True)
 invalid['layout']['shortcuts']=[];call('PATCH',endpoint,invalid,expected=400)
 del invalid['layout']['shortcuts'];call('PATCH',endpoint,invalid,expected=409)
shared=call('GET',path+'/dashboard',user='manager');shared['layout']['shortcuts']=[];call('PATCH',path+'/dashboard',shared,user='manager',expected=400)
current['layout']['shortcuts']=[];current=call('PATCH',endpoint,current);check(current['layout']['shortcuts']==[],'HTTP empty array remains array and hides all')
omitted=deepcopy(current);del omitted['layout']['shortcuts'];current=call('PATCH',endpoint,omitted);check(current['layout']['shortcuts']==[],'HTTP omission preserves empty preference')
current['layout']['shortcuts']=shortcut_default;current=call('PATCH',endpoint,current);check(current['layout']['shortcuts']==shortcut_default,'HTTP explicit defaults restore shortcuts')
untouched=dashboards();check(call('GET',endpoint)==current and dashboards()==untouched,'HTTP shortcut GET no writes')
check(call('GET',endpoint,user='other')==other_before,'HTTP shortcut preference isolated by account')
