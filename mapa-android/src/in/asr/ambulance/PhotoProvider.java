package in.asr.ambulance;
import android.content.*;
import android.database.Cursor;
import android.database.MatrixCursor;
import android.net.Uri;
import android.os.ParcelFileDescriptor;
import android.provider.OpenableColumns;
import java.io.*;

/** Only two camera outputs are exposed, never the encrypted store or arbitrary paths. */
public class PhotoProvider extends ContentProvider {
  static String filename(String purpose){if("selfie".equals(purpose))return "registration-selfie.jpg";if("completion".equals(purpose))return "closing.jpg";throw new SecurityException("Unknown camera purpose");}
  static Uri uri(String purpose){return Uri.parse("content://in.asr.ambulance.photos/"+filename(purpose));}
  static Uri uri(){return uri("completion");}
  static File file(Context c,String purpose){return new File(c.getFilesDir(),filename(purpose));}
  static File file(Context c){return file(c,"completion");}
  public boolean onCreate(){return true;}
  private File checked(Uri uri){if(uri("selfie").equals(uri))return file(getContext(),"selfie");if(uri("completion").equals(uri))return file(getContext(),"completion");throw new SecurityException("Unknown photograph");}
  public ParcelFileDescriptor openFile(Uri uri,String mode)throws FileNotFoundException {File file=checked(uri);if(!java.util.Arrays.asList("r","w","wt","rw","rwt").contains(mode))throw new SecurityException("Invalid camera access mode");return ParcelFileDescriptor.open(file,ParcelFileDescriptor.parseMode(mode));}
  public String getType(Uri uri){checked(uri);return "image/jpeg";}
  public Cursor query(Uri uri,String[] projection,String s,String[] args,String sort){File file=checked(uri);MatrixCursor c=new MatrixCursor(new String[]{OpenableColumns.DISPLAY_NAME,OpenableColumns.SIZE});c.addRow(new Object[]{file.getName(),file.length()});return c;}
  public Uri insert(Uri u,ContentValues v){throw new UnsupportedOperationException();}
  public int delete(Uri u,String s,String[] a){throw new UnsupportedOperationException();}
  public int update(Uri u,ContentValues v,String s,String[] a){throw new UnsupportedOperationException();}
}
