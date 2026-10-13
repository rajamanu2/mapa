package in.asr.ambulance;
import org.json.*;
import java.io.IOException;
import java.util.*;
public final class ActionQueueTest {
  static int assertions;
  static void check(boolean value,String why){assertions++;if(!value)throw new AssertionError(why);}
  static class Memory implements ActionQueue.Store {
    String disk="{}";boolean fail;
    public JSONObject read(){return new JSONObject(disk);}
    public void write(JSONObject value)throws Exception{if(fail)throw new IOException("disk full");disk=value.toString();}
  }
  static JSONObject body(){return new JSONObject().put("op","transition").put("id","trip").put("state","en_route").put("expected_state","accepted");}
  static String add(ActionQueue q,String trip)throws Exception{return q.add("server","crew","van",trip,body(),1000);}
  public static void main(String[] ignored)throws Exception{
    Memory m=new Memory();ActionQueue q=new ActionQueue(m);String id=add(q,"trip");
    check(new ActionQueue(m).visible("server","crew").getJSONObject(0).getJSONObject("body").getString("action_id").equals(id),"durable UUID survives restart");
    try{add(q,"trip");throw new AssertionError("duplicate");}catch(ActionQueue.Problem e){check(e.status==409,"one unresolved per trip");}
    int[] applied={0},sent={0};Map<String,String> receipts=new HashMap<>();
    ActionQueue.Transport uncertain=b->{sent[0]++;String key=b.getString("action_id");if(!receipts.containsKey(key)){receipts.put(key,b.toString());applied[0]++;throw new IOException("reply lost after server commit");}check(receipts.get(key).equals(b.toString()),"identical replay body");return new JSONObject().put("ok",true);};
    check(q.drain("server","crew","van",1000,uncertain).length()==0,"uncertain reply remains pending");
    check(q.visible("server","crew").length()==1,"intent retained");
    q.drain("server","crew","van",2000,uncertain);check(sent[0]==1,"backoff respected");
    q=new ActionQueue(m);check(q.drain("server","crew","van",6000,uncertain).has(id),"restart replays committed result");check(applied[0]==1&&sent[0]==2,"exactly one mock domain action");check(q.visible("server","crew").length()==0,"confirmed removed");
    add(q,"trip2");q.drain("other","crew","van",9000,uncertain);q.drain("server","other","van",9000,uncertain);check(sent[0]==2,"origin and owner isolation");
    q.drain("server","crew","other",9000,uncertain);check(q.visible("server","crew").getJSONObject(0).getString("state").equals("blocked"),"vehicle change blocks");q.retry("server","crew");q.drain("server","crew","van",9000,uncertain);check(sent[0]==2,"retry does not unblock conflict");
    String blocked=q.visible("server","crew").getJSONObject(0).getString("id");q.discard("server","other",blocked);check(q.visible("server","crew").length()==1,"discard owner isolation");q.discard("server","crew",blocked);
    add(q,"trip3");q.drain("server","crew","van",10000,b->{throw new ActionQueue.Problem(401,"expired");});check(q.visible("server","crew").getJSONObject(0).getString("state").equals("pending"),"401 retains pending");
    q.drain("server","crew","van",10000,b->{throw new ActionQueue.Problem(409,"newer revision");});check(q.visible("server","crew").getJSONObject(0).getString("message").equals("newer revision"),"conflict reason preserved");
    for(int status:new int[]{408,429,500,503})check(ActionQueue.retryable(status),"retryable "+status);
    for(int status:new int[]{400,401,403,404,409,422})check(!ActionQueue.retryable(status),"permanent "+status);
    Memory failed=new Memory();failed.fail=true;try{add(new ActionQueue(failed),"trip");throw new AssertionError("write must fail");}catch(IOException e){check(failed.disk.equals("{}"),"failed first persistence leaves no committed intent");}
    Memory removal=new Memory();ActionQueue r=new ActionQueue(removal);add(r,"trip");try{r.drain("server","crew","van",1000,b->{removal.fail=true;return new JSONObject();});throw new AssertionError("failed removal must surface");}catch(IOException e){check(removal.read().getJSONArray("actions").length()==1,"uncertain removal retains disk intent for safe replay");}
    JSONObject shown=new JSONObject().put("revision",1).put("state","accepted");JSONObject draft=DraftGuard.capture(body(),shown);shown.put("revision",2);DraftGuard.require(draft);check(draft.getInt("expected_revision")==1,"background refresh cannot relabel draft revision");
    Memory drafts=new Memory();ActionQueue dq=new ActionQueue(drafts);String draftId=dq.add("server","crew","van","draft",draft,1000);check(dq.visible("server","crew").getJSONObject(0).getJSONObject("body").getInt("expected_revision")==1,"original displayed revision durably stored");
    dq.drain("server","crew","van",1000,b->{check(b.getInt("expected_revision")==1,"old draft sent as old revision");throw new ActionQueue.Problem(409,"newer details");});check(dq.visible("server","crew").getJSONObject(0).getString("state").equals("blocked"),"newer details require review");
    try{DraftGuard.require(body());throw new AssertionError("unguarded draft");}catch(IOException e){check(true,"missing draft revision rejected");}
    System.out.println("Action queue: "+assertions+" assertions passed");
  }
}
