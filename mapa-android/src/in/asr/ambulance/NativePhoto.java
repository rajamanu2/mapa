package in.asr.ambulance;
import android.app.Activity;
import android.content.*;
import android.graphics.*;
import android.net.Uri;
import android.provider.MediaStore;
import java.io.*;

/** Camera-only capture to a narrowly granted private URI. Re-encoding strips EXIF metadata. */
final class NativePhoto {
  static final int MAX_BYTES=1024*1024;
  static void capture(Activity activity,String purpose,int request)throws Exception{
    File file=PhotoProvider.file(activity,purpose);if(file.exists()&&!file.delete())throw new IOException("Could not clear the earlier camera image.");
    Uri uri=PhotoProvider.uri(purpose);Intent intent=new Intent(MediaStore.ACTION_IMAGE_CAPTURE);intent.putExtra(MediaStore.EXTRA_OUTPUT,uri);intent.setClipData(ClipData.newRawUri("MAPA private camera output",uri));intent.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION|Intent.FLAG_GRANT_READ_URI_PERMISSION);
    if("selfie".equals(purpose)){intent.putExtra("android.intent.extras.CAMERA_FACING",1);intent.putExtra("android.intent.extra.USE_FRONT_CAMERA",true);}
    if(intent.resolveActivity(activity.getPackageManager())==null)throw new ActivityNotFoundException("This phone has no camera app. Contact control.");
    activity.startActivityForResult(intent,request);
  }
  static byte[] read(Context c,String purpose)throws Exception{
    File file=PhotoProvider.file(c,purpose);Bitmap image=null,resized=null,oriented=null;
    try{
      if(!file.isFile()||file.length()<64||file.length()>30L*1024*1024)throw new IOException("The camera did not save a usable photograph. Take it again.");
      BitmapFactory.Options bounds=new BitmapFactory.Options();bounds.inJustDecodeBounds=true;BitmapFactory.decodeFile(file.getPath(),bounds);
      if(bounds.outWidth<64||bounds.outHeight<64||bounds.outWidth>30000||bounds.outHeight>30000)throw new IOException("The camera photograph is invalid.");
      BitmapFactory.Options options=new BitmapFactory.Options();int sample=1;while(Math.max(bounds.outWidth,bounds.outHeight)/sample>1600)sample*=2;options.inSampleSize=sample;image=BitmapFactory.decodeFile(file.getPath(),options);if(image==null)throw new IOException("Could not read the camera photograph.");
      resized=image;int largest=Math.max(image.getWidth(),image.getHeight());if(largest>1280)resized=Bitmap.createScaledBitmap(image,Math.max(1,image.getWidth()*1280/largest),Math.max(1,image.getHeight()*1280/largest),true);
      android.media.ExifInterface exif=new android.media.ExifInterface(file.getPath());int orientation=exif.getAttributeInt(android.media.ExifInterface.TAG_ORIENTATION,android.media.ExifInterface.ORIENTATION_NORMAL);Matrix rotation=new Matrix();switch(orientation){case 2:rotation.setScale(-1,1);break;case 3:rotation.setRotate(180);break;case 4:rotation.setScale(1,-1);break;case 5:rotation.setRotate(90);rotation.postScale(-1,1);break;case 6:rotation.setRotate(90);break;case 7:rotation.setRotate(270);rotation.postScale(-1,1);break;case 8:rotation.setRotate(270);break;default:break;}if(orientation>=2&&orientation<=8)oriented=Bitmap.createBitmap(resized,0,0,resized.getWidth(),resized.getHeight(),rotation,true);else oriented=resized;
      ByteArrayOutputStream bytes=new ByteArrayOutputStream();for(int quality=85;quality>=40;quality-=15){bytes.reset();if(!oriented.compress(Bitmap.CompressFormat.JPEG,quality,bytes))throw new IOException("Could not prepare the camera photograph.");if(bytes.size()<=MAX_BYTES)return bytes.toByteArray();}throw new IOException("The camera photograph is too large. Retake it with fewer details.");
    }finally{if(oriented!=null&&oriented!=resized)oriented.recycle();if(resized!=null&&resized!=image)resized.recycle();if(image!=null)image.recycle();discard(c,purpose);}
  }
  static void cleanupOrphans(Context c){
    android.content.SharedPreferences prefs=NativeApi.prefs(c);String purpose=prefs.getString("camera_purpose","");long started=prefs.getLong("camera_requested_at",0),now=System.currentTimeMillis();boolean valid=("selfie".equals(purpose)||"completion".equals(purpose))&&NativeApi.server(c).equals(prefs.getString("camera_origin",""))&&started>0&&now>=started&&now-started<=300000;
    if(!valid){discard(c,"selfie");discard(c,"completion");prefs.edit().remove("camera_purpose").remove("camera_order").remove("camera_requested_at").remove("camera_origin").remove("camera_session").remove("camera_revision").remove("camera_scope").commit();}
    else discard(c,"selfie".equals(purpose)?"completion":"selfie");
  }
  static void discard(Context c,String purpose){try{c.revokeUriPermission(PhotoProvider.uri(purpose),Intent.FLAG_GRANT_WRITE_URI_PERMISSION|Intent.FLAG_GRANT_READ_URI_PERMISSION);PhotoProvider.file(c,purpose).delete();}catch(Exception ignored){}}
}
