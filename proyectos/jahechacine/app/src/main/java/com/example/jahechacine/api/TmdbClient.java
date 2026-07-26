package com.example.jahechacine.api;

import okhttp3.HttpUrl;
import okhttp3.Interceptor;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.logging.HttpLoggingInterceptor;
import retrofit2.Retrofit;
import retrofit2.converter.gson.GsonConverterFactory;

import java.io.IOException;

public class TmdbClient {
    private static final String BASE_URL = "https://api.themoviedb.org/3/";
    
    // Credenciales de TMDB. Se piden gratis en https://www.themoviedb.org/settings/api
    // (basta con la API key; el token Bearer es opcional, la API acepta cualquiera
    // de los dos). Se dejan vacías a proposito para no publicar credenciales en el
    // repositorio: rellena las tuyas antes de compilar.
    private static final String API_KEY = "TU_API_KEY_DE_TMDB";
    private static final String ACCESS_TOKEN = "TU_ACCESS_TOKEN_DE_TMDB";

    private static Retrofit retrofit = null;

    public static TmdbApiService getApiService() {
        if (retrofit == null) {
            // Interceptor to add both API Key (as param) and Access Token (as Header) 
            // to ensure maximum compatibility and avoid 401.
            Interceptor authInterceptor = chain -> {
                Request originalRequest = chain.request();
                
                // Add api_key as query parameter
                HttpUrl url = originalRequest.url().newBuilder()
                        .addQueryParameter("api_key", API_KEY)
                        .build();

                // Add Authorization Bearer Token as header
                Request request = originalRequest.newBuilder()
                        .url(url)
                        .addHeader("Authorization", "Bearer " + ACCESS_TOKEN)
                        .addHeader("accept", "application/json")
                        .build();
                        
                return chain.proceed(request);
            };

            HttpLoggingInterceptor logging = new HttpLoggingInterceptor();
            logging.setLevel(HttpLoggingInterceptor.Level.BASIC);

            OkHttpClient client = new OkHttpClient.Builder()
                    .addInterceptor(authInterceptor)
                    .addInterceptor(logging)
                    .build();

            retrofit = new Retrofit.Builder()
                    .baseUrl(BASE_URL)
                    .client(client)
                    .addConverterFactory(GsonConverterFactory.create())
                    .build();
        }
        return retrofit.create(TmdbApiService.class);
    }
}