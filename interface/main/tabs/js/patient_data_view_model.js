/**
 * patient_data_view_model.js
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

function encounter_data(id,date,category)
{
    var self=this;
    self.id=ko.observable(id);
    self.date=ko.observable(date);
    self.category=ko.observable(category);
    return this;
}

function patient_data_view_model(pname,pid,pubpid,str_dob,provider,insurance,allergies)
{
    var self=this;
    self.pname=ko.observable(pname);
    self.pid=ko.observable(pid);
    self.pubpid=ko.observable(pubpid);
    self.str_dob=ko.observable(str_dob);
    self.provider=ko.observable(provider || '');
    self.insurance=ko.observable(insurance || '');
    self.allergies=ko.observableArray(allergies || []);
    // Patient-picture URL.
    //
    // Stock OpenEMR points this at `controller.php?document&retrieve
    // &document_id=-1&context=patient_picture`, which dispatches into
    // `Controller` → `C_Document` → `CategoryTree` → `Tree::load_tree()`
    // — the same "Undefined array key -1" infinite loop that already
    // bit the upload + viewer paths (see `copilot_documents_upload.php`
    // and `copilot_documents_serve.php`). The body iframe never
    // resolves the request, so the patient demographics view sticks
    // on "infinite loading" with the headers visible. The `onError`
    // handler on the <img> can't help because the request never errors,
    // it just hangs.
    //
    // The AgentForge demo dataset has no real patient pictures, and
    // the stock controller couldn't serve them anyway. Always emit the
    // default-avatar PNG directly — same image OpenEMR's onError
    // fallback uses, just without the broken intermediate request.
    self.patient_picture=ko.computed(function(){
      return webroot_url + '/public/images/patient-picture-default.png';
    }, self);

    self.encounterArray=ko.observableArray();
    self.selectedEncounterID=ko.observable();
    self.selectedEncounter=ko.observable();
    self.selectedEncounterID.extend({notify: 'always'});
    self.selectedEncounterID.subscribe(function(newVal)
    {
       for(var encIdx=0;encIdx<self.encounterArray().length;encIdx++)
       {
           var curEnc=self.encounterArray()[encIdx];
           if(curEnc.id()==newVal)
           {

               self.selectedEncounter(curEnc);
               return;
           }
       }
       // No valid encounter ID found, so clear selected encounter;
       self.selectedEncounter(null);
    });
    return this;
}
