package com.example.jahechacine.model;

import com.google.gson.annotations.SerializedName;
import java.util.List;

public class TmdbResponse<T> {
    @SerializedName("results")
    private List<T> results;

    public List<T> getResults() {
        return results;
    }
}