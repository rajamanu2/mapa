package in.asr.ambulance;
import android.content.Context;
import android.util.AtomicFile;
import java.io.*;
import java.nio.charset.StandardCharsets;
import javax.crypto.Cipher;
import javax.crypto.spec.GCMParameterSpec;
import org.json.JSONObject;

final class PrivateStore implements ActionQueue.Store {
  private final AtomicFile file;
  PrivateStore(Context c,String name){file=new AtomicFile(new File(c.getFilesDir(),name+".enc"));}
  void erase(){file.delete();}
  public JSONObject read()throws Exception{
    byte[] bytes;try{bytes=file.readFully();}catch(FileNotFoundException e){return new JSONObject();}
    if(bytes.length<29||bytes.length>8000000)throw new IOException("Pending data cannot be read. Contact control; do not clear app storage.");
    Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.DECRYPT_MODE,NativeApi.key(),new GCMParameterSpec(128,bytes,0,12));
    return new JSONObject(new String(cipher.doFinal(bytes,12,bytes.length-12),StandardCharsets.UTF_8));
  }
  public void write(JSONObject state)throws Exception{
    byte[] plain=state.toString().getBytes(StandardCharsets.UTF_8);if(plain.length>7500000)throw new IOException("Pending storage is full. Sync before continuing.");
    Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.ENCRYPT_MODE,NativeApi.key());byte[] encrypted=cipher.doFinal(plain);
    FileOutputStream stream=null;try{stream=file.startWrite();stream.write(cipher.getIV());stream.write(encrypted);stream.getFD().sync();file.finishWrite(stream);stream=null;byte[] committed=file.readFully();byte[] expected=new byte[cipher.getIV().length+encrypted.length];System.arraycopy(cipher.getIV(),0,expected,0,cipher.getIV().length);System.arraycopy(encrypted,0,expected,cipher.getIV().length,encrypted.length);if(!java.util.Arrays.equals(expected,committed))throw new IOException("Pending storage could not be committed. Retry before continuing.");}catch(Exception e){if(stream!=null)file.failWrite(stream);throw e;}
  }
}
