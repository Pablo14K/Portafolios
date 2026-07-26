package app.nexus.data.db

import androidx.room.Dao
import androidx.room.Delete
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Upsert
import kotlinx.coroutines.flow.Flow

@Dao
interface SourceDao {
    @Query("SELECT * FROM sources ORDER BY priority ASC")
    fun observeAll(): Flow<List<SourceEntity>>

    @Query("SELECT * FROM sources ORDER BY priority ASC")
    suspend fun all(): List<SourceEntity>

    @Upsert
    suspend fun upsert(source: SourceEntity)

    @Query("DELETE FROM sources WHERE id = :id")
    suspend fun delete(id: String)

    @Query("UPDATE sources SET enabled = :enabled WHERE id = :id")
    suspend fun setEnabled(id: String, enabled: Boolean)

    @Query("UPDATE sources SET priority = :priority WHERE id = :id")
    suspend fun setPriority(id: String, priority: Int)

    @Query("UPDATE sources SET lastError = :error, lastCheckedAt = :at WHERE id = :id")
    suspend fun setHealth(id: String, error: String?, at: Long)
}

@Dao
interface ProgressDao {
    @Query("SELECT * FROM progress WHERE watched = 0 ORDER BY updatedAt DESC LIMIT :limit")
    fun observeContinueWatching(limit: Int = 30): Flow<List<ProgressEntity>>

    @Query("SELECT * FROM progress ORDER BY updatedAt DESC LIMIT :limit")
    fun observeHistory(limit: Int = 200): Flow<List<ProgressEntity>>

    @Query("SELECT * FROM progress WHERE `key` = :key")
    suspend fun find(key: String): ProgressEntity?

    @Query("SELECT * FROM progress WHERE mediaId = :mediaId ORDER BY updatedAt DESC")
    suspend fun forMedia(mediaId: String): List<ProgressEntity>

    @Upsert
    suspend fun upsert(progress: ProgressEntity)

    @Query("DELETE FROM progress WHERE `key` = :key")
    suspend fun delete(key: String)

    @Query("DELETE FROM progress")
    suspend fun clear()
}

@Dao
interface FavoriteDao {
    @Query("SELECT * FROM favorites ORDER BY addedAt DESC")
    fun observeAll(): Flow<List<FavoriteEntity>>

    @Query("SELECT EXISTS(SELECT 1 FROM favorites WHERE mediaId = :mediaId)")
    fun observeIsFavorite(mediaId: String): Flow<Boolean>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun add(favorite: FavoriteEntity)

    @Query("DELETE FROM favorites WHERE mediaId = :mediaId")
    suspend fun remove(mediaId: String)
}

@Dao
interface SearchHistoryDao {
    @Query("SELECT * FROM search_history ORDER BY searchedAt DESC LIMIT :limit")
    fun observeRecent(limit: Int = 12): Flow<List<SearchHistoryEntity>>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun record(entry: SearchHistoryEntity)

    @Delete
    suspend fun remove(entry: SearchHistoryEntity)

    @Query("DELETE FROM search_history")
    suspend fun clear()
}
